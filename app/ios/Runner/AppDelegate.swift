import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate {

  private var pushChannel: FlutterMethodChannel?
  // Token buffer: iOS sokszor a Flutter handler előtt küldi a tokent
  private var pendingToken: String?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)
    UNUserNotificationCenter.current().delegate = self

    // super hívja fel a FlutterViewController-t és a window-t
    let result = super.application(application, didFinishLaunchingWithOptions: launchOptions)

    // Window és FlutterViewController a super után bizton elérhető
    if let vc = window?.rootViewController as? FlutterViewController {
      let ch = FlutterMethodChannel(name: "push_channel", binaryMessenger: vc.binaryMessenger)
      pushChannel = ch
      ch.setMethodCallHandler { [weak self] (call: FlutterMethodCall, result: @escaping FlutterResult) in
        guard let self = self else { return }
        if call.method == "refreshToken" {
          if let token = self.pendingToken {
            // Token már megvan a bufferben — azonnali kézbesítés
            self.sendToFlutter("onToken", arguments: token)
          } else {
            // Még nem érkezett token — kérjük el iOS-tól
            DispatchQueue.main.async { UIApplication.shared.registerForRemoteNotifications() }
          }
          result(nil as Any?)
        } else {
          result(FlutterMethodNotImplemented)
        }
      }
    }

    requestPushPermission(application)
    return result
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
    pendingToken = token          // buffer: Flutter lehet hogy még nem áll készen
    sendToFlutter("onToken", arguments: token)
  }

  override func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    print("[APNs] Regisztráció sikertelen: \(error)")
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
