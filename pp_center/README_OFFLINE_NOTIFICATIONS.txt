Offline/online Mattermost notifications patch

- mqtt_worker.php: LWT offline -> Mattermost + alerts
- mqtt_worker.php: online recovery on telemetry/reported/LWT online when previous state was offline
- heartbeat_checker.php: timeout-based offline notification only once (online=1 -> online=0)
