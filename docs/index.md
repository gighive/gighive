<style>
body {
    background-color: #121a33;
    color: white;
}

/* Remove default Markdown heading styles and simulate custom headings */
.custom-h1 {
    font-size: 2em;      /* same as h1 */
    font-weight: bold;
    margin: 0.5em 0;
}

.custom-h2 {
    font-size: 1.5em;    /* same as h2 */
    font-weight: normal;
    margin: 0.5em 0;
    color: #ccc;         /* lighter shade for contrast, optional */
}

img {
    background: transparent !important;
}
</style>

<div class="custom-h1">Welcome to GigHive!</div>
<div class="custom-h2">Gighive is a media database for you, your fans, or wedding guests.</div>
### If you're a musician
- You can use it as a library of your bands sessions, audio and video files.
- Have your fans upload videos from your gigs and utilize the footage from every conceivable angle.

### If you're a wedding photographer
- You can have your guests upload audio and video files from a wedding that you can incorporate into a compilation video.
- Collect media from everyone during the event, offload it and then spin down the compute instance after you're done, thus saving you money.

### If you are a media librarian or have a preexisting cache of media files
- You can edit the default csv file to create your own historical Gighive.

### Why not just use YouTube?
- This site is for do-it-yourselfers who don't want to be beholden to Big Tech but be the masters of their own destiny.
- With build targets such as Azure, virtualbox or bare metal, you have your choice on how to deploy Gighive.
- Gighive frees you from content limitations on the major providers..but you'll need to size your vm properly.
- It is secure [by default](SECURITY.html) and was built from the ground up to live behind the [Cloudflare shield](https://www.cloudflare.com).
- Gighive is simple. There is one page for the media library and one upload page or easy-to-use iphone app..that's all.

### Requirements
1. Control Machine: Tested on Ubuntu 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS, installed on bare metal.**
2. Target Machine: Your choice of virtualbox, Azure or bare metal deployment targets for the vm and containerized environment.
These are shown in this <a href="images/architecture.png">architecture diagram</a>.

### What do I get with Gighive?
- Gighive includes a website with a searchable, sortable one-page listing of media files and common attributes (date, filename, etc) stored [in the database](images/databaseErd.png) along with an [upload utility](images/uploadutility.png).
- Common media formats for upload are supported (shown below).
- [Here are instructions](README.html) on how to standup and manage your own Gighive.

### Media formats supported
- Audio formats: MP3 (audio/mpeg, audio/mp3), WAV (audio/wav, audio/x-wav), AAC (audio/aac), FLAC (audio/flac), MP4 Audio (audio/mp4)
- Video formats: MP4 (video/mp4), QuickTime/MOV (video/quicktime), Matroska/MKV (video/x-matroska), WebM (video/webm), AVI (video/x-msvideo)
- Note that .MOV and .AVI don't autoplay in the browser

### For the future
- Eventually, we will develop more interesting features. But for now, we've keeping it simple and easy to manage.

### So give Gighive a try! For those with a bit of unix and command line experience, it will be a breeze to setup!
<a href="README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a> <a href="PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Parts List</a>
### License
GigHive is dual-licensed:

- **[MIT License](LICENSE_MIT.md)**: Free for personal, single-instance, non-commercial use.
- **[Commercial License](LICENSE_COMMERCIAL.md)**: Required for SaaS, multi-tenant, or commercial use.

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
