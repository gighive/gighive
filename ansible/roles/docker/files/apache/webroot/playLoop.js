// Pre-initialize AudioContext globally
const audioContext = new (window.AudioContext || window.webkitAudioContext)();

// Silent MP3 for unlocking audio on iOS
const soundEffect = new Audio();
soundEffect.autoplay = true;
soundEffect.src =
    "data:audio/mpeg;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gTGFTb25vdGhlcXVlLm9yZwBURU5DAAAAHQAAA1N3aXRjaCBQbHVzIMKpIE5DSCBTb2Z0d2FyZQBUSVQyAAAABgAAAzIyMzUAVFNTRQAAAA8AAANMYXZmNTcuODMuMTAwAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQMSkAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV";

// Unlock audio playback on the first user interaction
document.addEventListener(
    "click",
    () => {
        // Play silent MP3 to unlock audio playback
        soundEffect.play()
            .then(() => {
                console.log("Audio playback unlocked!");
            })
            .catch((err) => {
                console.error("Error unlocking audio:", err);
            });

        // Resume the AudioContext on user interaction
        if (audioContext.state === "suspended") {
            console.log("Resuming AudioContext on user interaction...");
            audioContext.resume()
                .then(() => {
                    console.log("AudioContext resumed.");
                })
                .catch((err) => {
                    console.error("Error resuming AudioContext:", err);
                });
        }
    },
    { once: true } // Ensure this runs only once
);

// Attach click listeners to dynamically created buttons
function attachListeners() {
    document.querySelectorAll('button[id^="playButton"]').forEach((button) => {
        if (!button.dataset.listenerAdded) {
            console.log(`Initializing button: ${button.id}`);
            button.addEventListener("click", () => {
                console.log(`Button clicked: ${button.id}`);
                const buttonId = button.id;
                const audioUrl =
                    "/audio/loops/" + buttonId.replace("playButton", "") + ".mp3";
                console.log(`Audio URL resolved: ${audioUrl}`);
                playLoop(audioUrl, buttonId);
            });
            button.dataset.listenerAdded = true; // Prevent duplicate listeners
        }
    });
}

// Delay attaching listeners until buttons are fully loaded
window.addEventListener("load", attachListeners);

// Function to play a looped sound
function playLoop(audioUrl, buttonId) {
    console.log(`playLoop called for ${audioUrl} on ${buttonId}`);
    let loopCount = 0; // Track the current loop count
    const maxLoops = 5; // Maximum number of loops

    async function loadAndPlayAudio() {
        try {
            console.log(`Loading audio from: ${audioUrl}`);
            const response = await fetch(audioUrl);
            const arrayBuffer = await response.arrayBuffer();
            const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
            console.log("Audio buffer loaded successfully.");

            function playAudio() {
                const sourceNode = audioContext.createBufferSource(); // Create a new source node for each loop
                sourceNode.buffer = audioBuffer;
                sourceNode.connect(audioContext.destination);

                sourceNode.onended = () => {
                    loopCount++;
                    console.log(`Loop ${loopCount} of ${maxLoops} completed.`);
                    if (loopCount < maxLoops) {
                        playAudio(); // Play the next loop
                    } else {
                        console.log("Playback completed after 5 loops.");
                    }
                };

                sourceNode.start(0); // Start playback
                console.log(`Starting playback for loop ${loopCount + 1}`);
            }

            // Start the first loop
            playAudio();
        } catch (err) {
            console.error("Error loading or playing audio:", err);
        }
    }

    // Load and start the audio playback
    loadAndPlayAudio();
}