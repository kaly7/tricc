import UIKit
import UniformTypeIdentifiers

class ShareViewController: UIViewController {

    private let hostAppBundleIdentifier = "com.rv42.babl42"
    private let appGroupId = "group.com.rv42.babl42"
    private let urlScheme = "ShareMedia-com.rv42.babl42"

    private var sharedMedia: [SharedMediaFile] = []
    private var totalItems = 0
    private var processedItems = 0

    override func viewDidLoad() {
        super.viewDidLoad()
        view.isHidden = true
        handleSharedFiles()
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
                handleTypedItem(provider: provider, typeId: UTType.image.identifier, mediaType: .image)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.movie.identifier) {
                handleTypedItem(provider: provider, typeId: UTType.movie.identifier, mediaType: .video)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.data.identifier) {
                handleTypedItem(provider: provider, typeId: UTType.data.identifier, mediaType: .file)
            } else {
                itemDone()
            }
        }
    }

    private func handleTypedItem(provider: NSItemProvider, typeId: String, mediaType: SharedMediaType) {
        provider.loadItem(forTypeIdentifier: typeId, options: nil) { [weak self] item, _ in
            guard let self = self else { return }
            if let url = item as? URL {
                let destPath = self.copyToSharedContainer(url: url)
                let media = SharedMediaFile(path: destPath, thumbnail: nil, duration: nil, type: mediaType)
                DispatchQueue.main.async { self.sharedMedia.append(media) }
            }
            self.itemDone()
        }
    }

    private func copyToSharedContainer(url: URL) -> String {
        let fm = FileManager.default
        guard let container = fm.containerURL(forSecurityApplicationGroupIdentifier: appGroupId) else {
            return url.path
        }
        let dest = container.appendingPathComponent("\(UUID().uuidString)-\(url.lastPathComponent)")
        try? fm.copyItem(at: url, to: dest)
        return dest.path
    }

    private func itemDone() {
        processedItems += 1
        if processedItems == totalItems {
            DispatchQueue.main.async { self.saveAndRedirect() }
        }
    }

    private func saveAndRedirect() {
        let encoder = JSONEncoder()
        if let data = try? encoder.encode(sharedMedia),
           let json = String(data: data, encoding: .utf8) {
            UserDefaults(suiteName: appGroupId)?.set(json, forKey: hostAppBundleIdentifier)
        }
        completeWithRedirect()
    }

    private func completeWithRedirect() {
        guard let url = URL(string: "\(urlScheme)://") else {
            extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
            return
        }
        var responder: UIResponder? = self
        while responder != nil {
            if let app = responder as? UIApplication {
                app.open(url, options: [:])
                break
            }
            responder = responder?.next
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
