# Installing VirtualBox on a MacBook M4 Pro

Yes—VirtualBox now has a native Apple Silicon version compatible with your M4 Pro.

1. Open the official [VirtualBox Downloads page](https://www.virtualbox.org/wiki/Downloads).
2. Under **VirtualBox Platform Packages**, choose **macOS / Apple Silicon hosts**. Do **not** download the Intel version.
3. Open the downloaded `.dmg`.
4. Double-click `VirtualBox.pkg` and complete the installer.
5. If macOS requests approval, open **System Settings → Privacy & Security**, approve Oracle’s software, and restart if prompted.
6. Launch VirtualBox from **Applications**.

## Important limitation

Your M4 Pro uses ARM architecture, so VirtualBox can run **ARM64/AArch64 operating systems only**. Download an ARM image such as:

- Ubuntu: choose the **ARM64** ISO
- Debian: choose **arm64**
- Windows 11: use a **Windows 11 ARM64** image

Ordinary Intel/AMD (`x86_64` or `amd64`) virtual-machine images will not run. VirtualBox on Apple Silicon also still has some limitations involving graphics, audio, storage, and Guest Additions. Oracle documents these in its [VirtualBox manual](https://download.virtualbox.org/virtualbox/7.1.6/UserManual.pdf).

For the smoothest Windows or Linux experience on an M4 Mac, **Parallels Desktop** is generally the polished paid option, while **UTM** is a good free alternative.
