import Flutter
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {

  // Token tárolása — Flutter lekérheti amikor már kész
  private var storedToken: String?
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

    // Channel beállítása közvetlenül a Flutter engine inicializálásakor
    guard let controller = engineBridge.pluginRegistry as? FlutterViewController else { return }
    pushChannel = FlutterMethodChannel(name: "push_channel", binaryMessenger: controller.binaryMessenger)
    pushChannel?.setMethodCallHandler { [weak self] call, result in
      switch call.method {
      case "getStoredToken":
        result(self?.storedToken)
      case "refreshToken":
        UIApplication.shared.registerForRemoteNotifications()
        result(nil)
      default:
        result(FlutterMethodNotImplemented)
      }
    }

    // Ha már van tárolt token (korán érkezett), azonnal elküldjük
    if let token = storedToken {
      pushChannel?.invokeMethod("onToken", arguments: token)
    }
  }

  private func requestPushPermission(_ application: UIApplication) {
    UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound]) { granted, _ in
      if granted {
        DispatchQueue.main.async {
          application.registerForRemoteNotifications()
        }
      }
    }
  }

  override func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
    let token = deviceToken.map { String(format: "%02x", $0) }.joined()
    storedToken = token
    // Ha a channel már kész, azonnal elküldjük; ha nem, a didInitializeImplicitFlutterEngine küldi
    pushChannel?.invokeMethod("onToken", arguments: token)
  }

  override func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    print("[APNs] Token regisztráció sikertelen: \(error)")
  }

  override func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification, withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
    let userInfo = notification.request.content.userInfo
    pushChannel?.invokeMethod("onMessage", arguments: userInfo)
    if #available(iOS 14.0, *) {
      completionHandler([.banner, .sound, .badge])
    } else {
      completionHandler([.alert, .sound, .badge])
    }
  }

  override func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse, withCompletionHandler completionHandler: @escaping () -> Void) {
    let userInfo = response.notification.request.content.userInfo
    pushChannel?.invokeMethod("onNotificationTap", arguments: userInfo)
    completionHandler()
  }
}
