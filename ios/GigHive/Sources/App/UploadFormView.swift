import SwiftUI
import PhotosUI
import UniformTypeIdentifiers

struct UploadFormView: View {
    @EnvironmentObject private var settings: SettingsStore

    @State private var eventDate = Date()
    @State private var orgName = "Enter band or event *"
    @State private var eventType = "band"
    @State private var label = ""
    @State private var autoGenerateLabel = false
    @State private var showAdmin = false
    @State private var participants = ""
    @State private var keywords = ""
    @State private var location = ""
    @State private var rating = ""
    @State private var notes = ""

    @State private var pickedURL: URL?
    @State private var isUploading = false
    
    @State private var statusText: String = ""
    @State private var showPHPicker = false
    @State private var showDocPicker = false
    @State private var showResultAlert = false
    @State private var alertMessage = ""

    var body: some View {
        Form {
            Section(header: Text("Server").font(.caption)) {
                TextField("Base URL (https://host)", text: $settings.baseURLString)
                    .autocapitalization(.none)
                    .disableAutocorrection(true)
                    .keyboardType(.URL)
                TextField("Basic user", text: $settings.basicUser)
                    .autocapitalization(.none)
                    .disableAutocorrection(true)
                SecureField("Basic password", text: $settings.basicPass)
                Picker("Default event type", selection: $settings.defaultEventType) {
                    Text("band").tag("band")
                    Text("wedding").tag("wedding")
                }
            }
            Section(header: Text("Meta").font(.caption)) {
                DatePicker("Event date *", selection: $eventDate, displayedComponents: .date)
                TextField("Organization *", text: $orgName)
                Picker("Event type *", selection: $eventType) {
                    Text("band").tag("band")
                    Text("wedding").tag("wedding")
                }
                TextField("Label *", text: $label)
                Toggle("Autogenerate label?", isOn: $autoGenerateLabel)
                Text("* = mandatory").font(.footnote).foregroundColor(.secondary)
            }
            Section(header: Text("Media").font(.caption)) {
                Button(action: { showPHPicker = true }) {
                    Text(pickedURL?.lastPathComponent ?? "Pick video from Photos *")
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)
                }
                .sheet(isPresented: $showPHPicker) {
                    PHPickerView(selectionHandler: { url in
                        self.pickedURL = url
                    })
                }

                Button(action: { showDocPicker = true }) {
                    Text("Pick file (video/audio) from Files *")
                        .lineLimit(1)
                        .minimumScaleFactor(0.8)
                }
                .sheet(isPresented: $showDocPicker) {
                    DocumentPickerView(allowedTypes: [.movie, .mpeg4Movie, .audio]) { url in
                        self.pickedURL = url
                    }
                }
            }
            Section(header: Text("ADMINISTRATION").font(.caption)) {
                Button(action: {
                    if settings.basicUser.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() == "admin" {
                        showAdmin.toggle()
                    } else {
                        statusText = "Admin credentials required"
                        alertMessage = "Please set the Basic user to 'admin' in the Server section to access admin fields."
                        showResultAlert = true
                    }
                }) { Text(showAdmin ? "Hide Admin Fields" : "For Admins") }
                if showAdmin {
                    TextField("Participants (comma-separated)", text: $participants)
                    TextField("Keywords", text: $keywords)
                    TextField("Location", text: $location)
                    TextField("Rating", text: $rating)
                    TextEditor(text: $notes).frame(minHeight: 80)
                }
            }
            if !statusText.isEmpty {
                Text(statusText).font(.footnote)
            }
            
        }
        .font(.callout)
        .listStyle(InsetGroupedListStyle())
        .navigationTitle("Gighive Upload")
        .navigationBarTitleDisplayMode(.inline)
        .disabled(isUploading)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button(isUploading ? "Uploading…" : "Upload") { Task { await upload() } }
                    .disabled(pickedURL == nil || label.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || isUploading)
            }
        }
        // For iOS 14 compatibility, avoid .ignoresSafeArea(.keyboard, ...)
        .alert(isPresented: $showResultAlert) {
            Alert(title: Text("Upload"), message: Text(alertMessage), dismissButton: .default(Text("OK")))
        }
        .onChange(of: autoGenerateLabel) { on in
            // persist preference
            settings.autoGenerateLabel = on
            if on { label = "Auto " + formatYMD(eventDate) }
        }
        .onChange(of: eventDate) { _ in
            if autoGenerateLabel { label = "Auto " + formatYMD(eventDate) }
        }
        .onAppear {
            // initialize toggle from persisted preference
            autoGenerateLabel = settings.autoGenerateLabel
            if autoGenerateLabel && label.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                label = "Auto " + formatYMD(eventDate)
            }
        }
    }
    
    private func upload() async {
        guard let url = pickedURL else { return }
        guard let baseURL = URL(string: settings.baseURLString) else { statusText = "Invalid Base URL"; return }
        let trimmedLabel = label.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmedLabel.isEmpty {
            statusText = "Label is required"
            alertMessage = statusText
            showResultAlert = true
            return
        }
        let client = UploadClient(baseURL: baseURL, basicAuth: (settings.basicUser, settings.basicPass))
        statusText = "Uploading…"
        let payload = UploadPayload(
            fileURL: url,
            eventDate: eventDate,
            orgName: orgName,
            eventType: eventType,
            label: trimmedLabel,
            participants: showAdmin ? emptyToNil(participants) : nil,
            keywords: emptyToNil(keywords),
            location: emptyToNil(location),
            rating: emptyToNil(rating),
            notes: emptyToNil(notes)
        )
        isUploading = true
        defer { isUploading = false }
        do {
            let (status, data, _) = try await client.upload(payload)
            let bodyText = String(data: data, encoding: .utf8) ?? "(no body)"
            switch status {
            case 201:
                statusText = "Success"
                alertMessage = "Upload succeeded.\n\nResponse: \n\(bodyText)"
            case 401:
                statusText = "Unauthorized"
                alertMessage = "401 Unauthorized. Check Basic Auth username/password."
            case 413:
                statusText = "Payload Too Large"
                alertMessage = "413 Payload Too Large. Increase server limits or pick a smaller file."
            case 400:
                statusText = "Bad Request: \(bodyText)"
                alertMessage = statusText
            default:
                statusText = "HTTP \(status): \(bodyText)"
                alertMessage = statusText
            }
            showResultAlert = true
        } catch {
            statusText = "Error: \(error.localizedDescription)"
            alertMessage = statusText
            showResultAlert = true
        }
    }

    private func emptyToNil(_ s: String) -> String? { s.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : s }

    private func formatYMD(_ date: Date) -> String {
        let df = DateFormatter()
        df.calendar = Calendar(identifier: .gregorian)
        df.locale = Locale(identifier: "en_US_POSIX")
        df.dateFormat = "yyyy-MM-dd"
        return df.string(from: date)
    }
}
