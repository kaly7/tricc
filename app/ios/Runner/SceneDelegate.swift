import Flutter
import UIKit

class SceneDelegate: FlutterSceneDelegate {

    // App háttérből előtérbe (URL scheme-mel nyitják meg)
    override func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
        super.scene(scene, openURLContexts: URLContexts)
        if let url = URLContexts.first?.url {
            _ = UIApplication.shared.delegate?.application?(UIApplication.shared, open: url, options: [:])
        }
    }

    // App cold-start URL scheme-mel indítva
    override func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        super.scene(scene, willConnectTo: session, options: connectionOptions)
        if let url = connectionOptions.urlContexts.first?.url {
            _ = UIApplication.shared.delegate?.application?(UIApplication.shared, open: url, options: [:])
        }
    }
}
