package com.rv42.tricc

import android.content.Context
import android.os.PowerManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {

    private var proximityWakeLock: PowerManager.WakeLock? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        val powerManager = getSystemService(Context.POWER_SERVICE) as PowerManager

        @Suppress("DEPRECATION")
        proximityWakeLock = powerManager.newWakeLock(
            PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK,
            "BabL42:proximity"
        )

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "com.rv42.babl42/proximity")
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "enable" -> {
                        if (proximityWakeLock?.isHeld == false) {
                            proximityWakeLock?.acquire()
                        }
                        result.success(null)
                    }
                    "disable" -> {
                        if (proximityWakeLock?.isHeld == true) {
                            proximityWakeLock?.release()
                        }
                        result.success(null)
                    }
                    else -> result.notImplemented()
                }
            }
    }

    override fun onDestroy() {
        if (proximityWakeLock?.isHeld == true) proximityWakeLock?.release()
        super.onDestroy()
    }
}
