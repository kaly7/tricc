#!/usr/bin/env python3
"""
agvmgr MQTT worker – VDA 5050 v2.0 pozíció rögzítő + Omron továbbítás
"""

import json
import math
import logging
import sys
import signal
import time
from datetime import datetime, timezone

import mysql.connector
import paho.mqtt.client as mqtt

# ── Konfiguráció ─────────────────────────────────────────────────────────────
DB_CONFIG = {
    'host':       'localhost',
    'user':       'robot',
    'password':   'abrakadabra',
    'database':   'agvmgr',
    'charset':    'utf8mb4',
    'autocommit': True,
}
LOG_FILE = '/var/log/agvmgr_worker.log'

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout),
    ]
)
log = logging.getLogger(__name__)

# ── Globális állapot ──────────────────────────────────────────────────────────
_db_conn      = None
topic_map     = {}   # full_topic -> agv_id
agv_meta      = {}   # agv_id -> {'name': ..., 'serial_no': ...}
omron_fwd     = {}   # agv_id -> {'topic': ..., 'fields': [...], 'enabled': bool}
omron_client  = None


# ── DB kapcsolat ──────────────────────────────────────────────────────────────
def db_get():
    global _db_conn
    try:
        if _db_conn and _db_conn.is_connected():
            return _db_conn
    except Exception:
        pass
    _db_conn = mysql.connector.connect(**DB_CONFIG)
    log.info("DB kapcsolat megnyitva")
    return _db_conn


def db_query(sql, params=None, fetchone=False):
    conn = db_get()
    cur  = conn.cursor(dictionary=True)
    cur.execute(sql, params or ())
    result = cur.fetchone() if fetchone else cur.fetchall()
    cur.close()
    return result


def db_execute(sql, params=None):
    conn = db_get()
    cur  = conn.cursor()
    cur.execute(sql, params or ())
    cur.close()


# ── Konfig betöltés ───────────────────────────────────────────────────────────
def load_agvs():
    global topic_map, agv_meta
    rows = db_query("SELECT id, name, serial_no, topic FROM agv WHERE enabled = 1")
    topic_map = {}
    agv_meta  = {}
    for row in rows:
        base = row['topic'].rstrip('/')
        topic_map[base + '/visualization'] = row['id']
        topic_map[base + '/state']         = row['id']
        agv_meta[row['id']] = {'name': row['name'] or row['serial_no'], 'serial_no': row['serial_no']}
    log.info(f"Betöltve {len(rows)} AGV, {len(topic_map)} topic figyelve")
    return list(topic_map.keys())


def load_omron_fwd():
    global omron_fwd
    rows = db_query("""
        SELECT f.agv_id, f.topic_template, f.fields, f.enabled
        FROM omron_forward f
        JOIN agv a ON a.id = f.agv_id
        WHERE a.enabled = 1
    """)
    omron_fwd = {}
    for row in rows:
        fields = json.loads(row['fields']) if row['fields'] else []
        omron_fwd[row['agv_id']] = {
            'topic':   row['topic_template'],
            'fields':  fields,
            'enabled': bool(row['enabled']),
        }
    log.info(f"Omron forward konfig betöltve: {len(omron_fwd)} AGV")


def get_broker_config(table='mqtt_broker'):
    return db_query(f"SELECT ip, port, username, password FROM {table} WHERE id=1", fetchone=True)


# ── Payload mentése DB-be ─────────────────────────────────────────────────────
def save_position(agv_id: int, payload_raw: str, source: str):
    try:
        payload = json.loads(payload_raw)
    except json.JSONDecodeError as e:
        log.warning(f"JSON parse hiba (agv {agv_id}): {e}")
        return None

    pos = payload.get('agvPosition') or {}
    vel = payload.get('velocity')    or {}
    bat = payload.get('batteryState') or {}

    x          = pos.get('x')
    y          = pos.get('y')
    theta      = pos.get('theta')
    map_id     = pos.get('mapId', '') or ''
    pos_init   = pos.get('positionInitialized')
    loc_score  = pos.get('localizationScore')
    dev_range  = pos.get('deviationRange')
    vx         = vel.get('vx')
    vy         = vel.get('vy')
    omega      = vel.get('omega')
    bat_charge = bat.get('batteryCharge')
    bat_volt   = bat.get('batteryVoltage')
    op_mode    = payload.get('operatingMode') or ''
    driving    = payload.get('driving')
    paused     = payload.get('paused')

    speed_str = ''
    if vx is not None and vy is not None:
        speed_str = f"  v={math.sqrt(vx**2+vy**2):.2f}m/s"
    deg_str = f"{math.degrees(theta):.1f}°" if theta is not None else '–'
    log.info(f"AGV {agv_id} [{source}]  x={x} y={y} θ={deg_str}{speed_str}  bat={bat_charge}%  mode={op_mode or '–'}")

    try:
        db_execute("""
            INSERT INTO agv_coords
              (agv_id, x, y, theta, map_id, position_initialized,
               localization_score, deviation_range,
               vx, vy, omega,
               battery_charge, battery_voltage,
               operating_mode, driving, paused,
               source, raw_payload, updated_at)
            VALUES
              (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NOW(3))
            ON DUPLICATE KEY UPDATE
              x                    = COALESCE(%s, x),
              y                    = COALESCE(%s, y),
              theta                = COALESCE(%s, theta),
              map_id               = IF(%s <> '', %s, map_id),
              position_initialized = COALESCE(%s, position_initialized),
              localization_score   = COALESCE(%s, localization_score),
              deviation_range      = COALESCE(%s, deviation_range),
              vx                   = COALESCE(%s, vx),
              vy                   = COALESCE(%s, vy),
              omega                = COALESCE(%s, omega),
              battery_charge       = COALESCE(%s, battery_charge),
              battery_voltage      = COALESCE(%s, battery_voltage),
              operating_mode       = IF(%s <> '', %s, operating_mode),
              driving              = COALESCE(%s, driving),
              paused               = COALESCE(%s, paused),
              source               = %s,
              raw_payload          = %s,
              updated_at           = NOW(3)
        """, (
            agv_id, x, y, theta, map_id, pos_init,
            loc_score, dev_range, vx, vy, omega,
            bat_charge, bat_volt, op_mode, driving, paused,
            source, payload_raw,
            x, y, theta,
            map_id, map_id,
            pos_init, loc_score, dev_range,
            vx, vy, omega,
            bat_charge, bat_volt,
            op_mode, op_mode,
            driving, paused,
            source, payload_raw,
        ))
    except Exception as e:
        log.error(f"DB mentési hiba (agv {agv_id}): {e}")
        global _db_conn
        _db_conn = None

    # Visszaadjuk a parsolt adatokat az Omron forwarding-hoz
    return {
        'x': x, 'y': y, 'theta': theta,
        'theta_deg': round(math.degrees(theta), 4) if theta is not None else None,
        'map_id': map_id or None,
        'pos_init': pos_init,
        'loc_score': loc_score,
        'dev_range': dev_range,
        'vx': vx, 'vy': vy, 'omega': omega,
        'speed': round(math.sqrt(vx**2+vy**2), 4) if (vx is not None and vy is not None) else None,
        'battery': bat_charge,
        'voltage': bat_volt,
        'mode': op_mode or None,
        'driving': driving,
        'paused': paused,
    }


# ── Omron forwarding ──────────────────────────────────────────────────────────
def forward_to_omron(agv_id: int, parsed: dict):
    global omron_client
    if omron_client is None:
        return

    cfg = omron_fwd.get(agv_id)
    if not cfg or not cfg['enabled'] or not cfg['fields']:
        return

    meta = agv_meta.get(agv_id, {})
    topic = cfg['topic'] \
        .replace('{serial_no}', meta.get('serial_no', str(agv_id))) \
        .replace('{name}',      meta.get('name', str(agv_id)))

    out = {}
    field_map = {
        'x':         ('x',         parsed.get('x')),
        'y':         ('y',         parsed.get('y')),
        'theta':     ('theta',     parsed.get('theta')),
        'theta_deg': ('theta_deg', parsed.get('theta_deg')),
        'map_id':    ('map_id',    parsed.get('map_id')),
        'pos_init':  ('positionInitialized', parsed.get('pos_init')),
        'loc_score': ('localizationScore',   parsed.get('loc_score')),
        'dev_range': ('deviationRange',      parsed.get('dev_range')),
        'speed':     ('speed',     parsed.get('speed')),
        'vx':        ('vx',        parsed.get('vx')),
        'vy':        ('vy',        parsed.get('vy')),
        'omega':     ('omega',     parsed.get('omega')),
        'battery':   ('batteryCharge',   parsed.get('battery')),
        'voltage':   ('batteryVoltage',  parsed.get('voltage')),
        'mode':      ('operatingMode',   parsed.get('mode')),
        'driving':   ('driving',   parsed.get('driving')),
        'paused':    ('paused',    parsed.get('paused')),
        'timestamp': ('timestamp', datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%f')[:-3] + 'Z'),
        'agv_name':  ('agvName',   meta.get('name')),
        'serial_no': ('serialNo',  meta.get('serial_no')),
    }

    for key in cfg['fields']:
        if key in field_map:
            json_key, value = field_map[key]
            if value is not None:
                out[json_key] = value

    if not out:
        return

    try:
        omron_client.publish(topic, json.dumps(out), qos=1, retain=False)
        log.debug(f"Omron forward: {topic} → {list(out.keys())}")
    except Exception as e:
        log.warning(f"Omron publish hiba (agv {agv_id}): {e}")


# ── MQTT callbacks (AGV broker) ───────────────────────────────────────────────
def on_connect(client, userdata, flags, rc):
    codes = {0:'OK',1:'protokoll hiba',2:'érvénytelen ID',3:'szerver nem elérhető',4:'hibás jelszó',5:'nincs jog'}
    if rc == 0:
        log.info("AGV MQTT broker kapcsolódva")
        for topic in topic_map:
            client.subscribe(topic, qos=1)
            log.info(f"  → feliratkozva: {topic}")
    else:
        log.error(f"AGV MQTT kapcsolódás meghiúsult: {codes.get(rc, rc)}")


def on_message(client, userdata, msg):
    topic  = msg.topic
    agv_id = topic_map.get(topic)
    if agv_id is None:
        return
    source = 'visualization' if topic.endswith('/visualization') else 'state'
    payload_raw = msg.payload.decode('utf-8', errors='replace')
    parsed = save_position(agv_id, payload_raw, source)
    if parsed:
        forward_to_omron(agv_id, parsed)


def on_disconnect(client, userdata, rc):
    if rc != 0:
        log.warning(f"AGV MQTT kapcsolat megszakadt (rc={rc}), újracsatlakozás...")


# ── Omron MQTT kliens setup ───────────────────────────────────────────────────
def setup_omron_client():
    global omron_client
    omron_cfg = db_query("SELECT ip, port, username, password, enabled FROM omron_broker WHERE id=1", fetchone=True)

    if not omron_cfg or not omron_cfg.get('enabled') or not omron_cfg.get('ip'):
        if omron_cfg and omron_cfg.get('enabled') and not omron_cfg.get('ip'):
            log.warning("Omron forwarding engedélyezve, de nincs IP beállítva")
        else:
            log.info("Omron forwarding kikapcsolva")
        omron_client = None
        return

    c = mqtt.Client(client_id="agvmgr_omron_fwd", clean_session=True)
    if omron_cfg.get('username'):
        c.username_pw_set(omron_cfg['username'], omron_cfg.get('password') or '')

    c.reconnect_delay_set(min_delay=5, max_delay=60)

    def omron_on_connect(cl, ud, fl, rc):
        if rc == 0: log.info(f"Omron MQTT broker kapcsolódva ({omron_cfg['ip']}:{omron_cfg['port']})")
        else:       log.error(f"Omron MQTT kapcsolódás meghiúsult: rc={rc}")

    def omron_on_disconnect(cl, ud, rc):
        if rc != 0: log.warning(f"Omron MQTT kapcsolat megszakadt (rc={rc})")

    c.on_connect    = omron_on_connect
    c.on_disconnect = omron_on_disconnect

    try:
        c.connect(omron_cfg['ip'], int(omron_cfg['port']), keepalive=60)
        c.loop_start()
        omron_client = c
        log.info(f"Omron MQTT kliens elindítva → {omron_cfg['ip']}:{omron_cfg['port']}")
    except Exception as e:
        log.error(f"Omron MQTT csatlakozási hiba: {e}")
        omron_client = None


# ── Főprogram ─────────────────────────────────────────────────────────────────
def main():
    log.info("agvmgr MQTT worker indul")

    load_agvs()
    load_omron_fwd()

    broker = get_broker_config('mqtt_broker')
    if not broker or not broker.get('ip'):
        log.error("Nincs AGV MQTT broker IP beállítva. Kilépés.")
        sys.exit(1)

    setup_omron_client()

    client = mqtt.Client(client_id="agvmgr_worker", clean_session=True)
    client.on_connect    = on_connect
    client.on_message    = on_message
    client.on_disconnect = on_disconnect

    if broker.get('username'):
        client.username_pw_set(broker['username'], broker.get('password') or '')

    client.reconnect_delay_set(min_delay=2, max_delay=30)

    def shutdown(sig, frame):
        log.info("Worker leállítás (signal fogadva)")
        client.disconnect()
        if omron_client:
            omron_client.loop_stop()
            omron_client.disconnect()
        sys.exit(0)

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT,  shutdown)

    log.info(f"Csatlakozás AGV brokerhez: {broker['ip']}:{broker['port']}")
    try:
        client.connect(broker['ip'], int(broker['port']), keepalive=60)
    except Exception as e:
        log.error(f"AGV MQTT csatlakozási hiba: {e}")
        sys.exit(1)

    client.loop_forever(retry_first_connection=True)


if __name__ == '__main__':
    main()
