import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {

  private var pushChannel: FlutterMethodChannel?
  private var pendingToken: String?
  private var proximityChannel: FlutterMethodChannel?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    UNUserNotificationCenter.current().delegate = self
    requestPushPermission(application)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  // SceneDelegate-es Flutter appban ez a megfelelő belépési pont a csatorna beállításához
  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    // Proximity channel — kijelző ki/be a közelségérzékelő alapján
    if let proxReg = engineBridge.pluginRegistry.registrar(forPlugin: "ProximityPlugin") {
      let proxCh = FlutterMethodChannel(name: "com.rv42.babl42/proximity", binaryMessenger: proxReg.messenger())
      proximityChannel = proxCh
      proxCh.setMethodCallHandler { (call: FlutterMethodCall, result: @escaping FlutterResult) in
        DispatchQueue.main.async {
          switch call.method {
          case "enable":
            UIDevice.current.isProximityMonitoringEnabled = true
          case "disable":
            UIDevice.current.isProximityMonitoringEnabled = false
          default: break
          }
          result(nil as Any?)
        }
      }
    }

    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "PushChannelPlugin") else { return }
    let ch = FlutterMethodChannel(name: "push_channel", binaryMessenger: registrar.messenger())
    pushChannel = ch
    ch.setMethodCallHandler { [weak self] (call: FlutterMethodCall, result: @escaping FlutterResult) in
      guard let self = self else { return }
      if call.method == "refreshToken" {
        if let token = self.pendingToken {
          self.sendToFlutter("onToken", arguments: token)
        } else {
          DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
        }
        result(nil as Any?)
      } else if call.method == "setBadge" {
        let count = call.arguments as? Int ?? 0
        DispatchQueue.main.async { UIApplication.shared.applicationIconBadgeNumber = count }
        result(nil as Any?)
      } else {
        result(FlutterMethodNotImplemented)
      }
    }
  }

  private func requestPushPermission(_ application: UIApplication) {
    UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, _ in
      if granted {
        DispatchQueue.main.async { application.registerForRemoteNotifications() }
      }
    }
  }

  override func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
    let token = deviceToken.map { String(format: "%02x", $0) }.joined()
    pendingToken = token
    sendToFlutter("onToken", arguments: token)
  }

  override func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    print("[APNs] Sikertelen: \(error)")
  }

  override func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
    sendToFlutter("onMessage", arguments: notification.request.content.userInfo)
    if #available(iOS 14.0, *) {
      completionHandler([.banner, .sound, .badge])
    } else {
      completionHandler([.alert, .sound, .badge])
    }
  }

  override func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler completionHandler: @escaping () -> Void) {
    sendToFlutter("onNotificationTap", arguments: response.notification.request.content.userInfo)
    completionHandler()
  }

  private func sendToFlutter(_ method: String, arguments: Any?) {
    DispatchQueue.main.async {
      self.pushChannel?.invokeMethod(method, arguments: arguments)
    }
  }
}
