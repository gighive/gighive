<style>
body {
    background-color: #121a33;
    color: white;
}
</style>

# Welcome to GigHive!

<img src="images/beelogo.png" alt="GigHive bee mascot holding a camera and microphone" width="200" height="200">

## Gighive is a media database for you, your fans, or wedding guests.

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
- Also, using Gighive frees you up from content limitations on the major providers..but plan out how you size your VM.
- With build targets such as Azure, virtualbox or bare metal, you have your choice on how to deploy Gighive.
- And although it is secure [by default](SECURITY.html), Gighive was built from the ground up to live behind the [Cloudflare shield](https://www.cloudflare.com).
- Last but not least, Gighive is simple. There is one page for the media library and one upload utility..that's all.

### How do I get started?
- Gighive includes a searchable, sortable one-page listing of media files and common attributes (date, location, people, rating, etc) stored [in the database](images/databaseErd.png) along with an [upload utility](images/uploadutility.png).
- Here are [instructional videos](comingsoon.html) on how to standup and manage your own Gighive.

### REQUIREMENTS
- Tested on Ubuntu 22.04, so the requirements are **any flavor of Ubuntu 22.04 or Pop-OS, installed on bare metal.**
- Your choice of virtualbox, Azure or bare metal deployment targets for the vm and containerized environment.

### For the future
- Eventually, we will develop more interesting features. But for now, we've keeping it simple and easy to manage.

### So give Gighive a try! For those with a bit of unix and command line experience, it will be a breeze to setup!

<a href="PREREQS.html" style="display: inline-block; background-color: #4CAF50; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">Examine the pre-requisites</a>

<a href="README.html" style="display: inline-block; background-color: #2196F3; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 8px 4px; transition: background-color 0.3s;">View the README</a>

### License
GigHive is dual-licensed:

- **[MIT License](LICENSE_MIT.md)**: Free for personal, single-instance, non-commercial use.
- **[Commercial License](LICENSE_COMMERCIAL.md)**: Required for SaaS, multi-tenant, or commercial use.

👉 [Contact us](mailto:contactus@gighive.app) for commercial licensing or for any other questions regarding Gighive. <img src="images/beelogo.png" alt="GigHive bee mascot" style="height: 1em; vertical-align: middle;">
