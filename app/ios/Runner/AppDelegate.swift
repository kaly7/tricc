import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {

  // Tárolt csatorna — ugyanaz a messenger, amelyen a Flutter handler regisztrálva van
  private var pushChannel: FlutterMethodChannel?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    UNUserNotificationCenter.current().delegate = self
    requestPushPermission(application)
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    guard let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "PushChannelPlugin") else { return }
    let channel = FlutterMethodChannel(name: "push_channel", binaryMessenger: registrar.messenger())
    pushChannel = channel
    channel.setMethodCallHandler { (call: FlutterMethodCall, result: @escaping FlutterResult) in
      if call.method == "refreshToken" {
        DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
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
    print("[APNs] Token érkezett: \(token.prefix(20))...")
    sendToFlutter("onToken", arguments: token)
  }

  override func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    print("[APNs] Token regisztráció sikertelen: \(error)")
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
      if let channel = self.pushChannel {
        channel.invokeMethod(method, arguments: arguments)
      }
    }
  }
}
