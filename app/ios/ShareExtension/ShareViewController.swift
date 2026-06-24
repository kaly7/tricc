import UIKit
import UniformTypeIdentifiers

class ShareViewController: UIViewController {

    private var appGroupId = ""
    private var hostAppBundleIdentifier = ""
    private var sharedMedia: [SharedMediaFile] = []
    private var totalItems = 0
    private var processedItems = 0

    override func viewDidLoad() {
        super.viewDidLoad()
        view.isHidden = true
        loadIds()
        handleSharedFiles()
    }

    private func loadIds() {
        let extBundle = Bundle.main.bundleIdentifier!
        let lastDot = extBundle.lastIndex(of: ".")!
        hostAppBundleIdentifier = String(extBundle[..<lastDot])
        appGroupId = "group.\(hostAppBundleIdentifier)"
    }

    private func handleSharedFiles() {
        guard let extensionItem = extensionContext?.inputItems.first as? NSExtensionItem,
              let attachments = extensionItem.attachments,
              !attachments.isEmpty else {
            completeWithRedirect()
            return
        }

        totalItems = attachments.count

        for provider in attachments {
            if provider.hasItemConformingToTypeIdentifier(UTType.image.identifier) {
                handleItem(provider: provider, typeId: UTType.image.identifier, mediaType: .image)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.movie.identifier) {
                handleItem(provider: provider, typeId: UTType.movie.identifier, mediaType: .video)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.data.identifier) {
                handleItem(provider: provider, typeId: UTType.data.identifier, mediaType: .file)
            } else {
                itemDone()
            }
        }
    }

    private func handleItem(provider: NSItemProvider, typeId: String, mediaType: SharedMediaType) {
        // loadFileRepresentation minden típust (URL, UIImage, Data) fájllá konvertál,
        // így képernyőfotó (Data) és Photos app (URL) egyformán működik
        provider.loadFileRepresentation(forTypeIdentifier: typeId) { [weak self] url, _ in
            guard let self = self else { return }
            var path = ""
            if let url = url {
                path = self.copyToContainer(url: url)
            }
            if !path.isEmpty {
                let media = SharedMediaFile(path: path, thumbnail: nil, duration: nil, type: mediaType)
                DispatchQueue.main.async { self.sharedMedia.append(media) }
            }
            self.itemDone()
        }
    }

    private func copyToContainer(url: URL) -> String {
        let fm = FileManager.default
        guard let container = fm.containerURL(forSecurityApplicationGroupIdentifier: appGroupId) else {
            return url.absoluteString
        }
        let dest = container.appendingPathComponent("\(UUID().uuidString)-\(url.lastPathComponent)")
        try? fm.copyItem(at: url, to: dest)
        // Use absoluteString (file:// prefix) — plugin strips it via getAbsolutePath
        return dest.absoluteString.removingPercentEncoding ?? dest.absoluteString
    }

    private func itemDone() {
        processedItems += 1
        if processedItems == totalItems {
            DispatchQueue.main.async { self.saveAndRedirect() }
        }
    }

    private func saveAndRedirect() {
        let userDefaults = UserDefaults(suiteName: appGroupId)
        // Key: "ShareKey" — ezt várja a receive_sharing_intent plugin
        // Format: Data (bináris JSON) — nem String
        if let data = try? JSONEncoder().encode(sharedMedia) {
            userDefaults?.set(data, forKey: "ShareKey")
            userDefaults?.synchronize()
        }
        completeWithRedirect()
    }

    private func completeWithRedirect() {
        // URL: "ShareMedia-{hostBundle}:share" — ezt veri a plugin scheme prefix check
        guard let url = URL(string: "ShareMedia-\(hostAppBundleIdentifier):share") else {
            extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
            return
        }

        var responder: UIResponder? = self

        if #available(iOS 18.0, *) {
            while responder != nil {
                if let app = responder as? UIApplication {
                    app.open(url, options: [:])
                }
                responder = responder?.next
            }
        } else {
            let selector = sel_registerName("openURL:")
            while responder != nil {
                if responder?.responds(to: selector) == true {
                    _ = responder?.perform(selector, with: url)
                }
                responder = responder?.next
            }
        }

        extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
    }
}

struct SharedMediaFile: Codable {
    let path: String
    let thumbnail: String?
    let duration: Double?
    let type: SharedMediaType
}

enum SharedMediaType: String, Codable {
    case image, video, file, url, text
}
