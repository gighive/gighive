// Enhanced Modern Timeline Component for StormPigs with XML loading and zoom
class StormPigsTimeline {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.events = [];
        this.selectedEvent = null;
        this.isDragging = false;
        this.dragMoved = false; // track whether a drag occurred to suppress click
        this.startX = 0;
        this.scrollLeft = 0;
        this.zoomLevel = 0.63;
        this.minZoom = 0.2;
        this.maxZoom = 20;
        this.yearRange = { min: 1997, max: 2025 };
        
        this.loadTimelineData();
    }

    async loadTimelineData() {
        try {
            // Try to load from database API first
            const response = await fetch('timeline/timeline-api.php');
            if (response.ok) {
                const data = await response.json();
                console.log('Timeline API Response:', data);
                
                // Handle new response format with debug info
                if (data.events) {
                    this.events = data.events;
                    console.log(`Loaded ${this.events.length} events from database`);
                } else {
                    // Fallback for old format
                    this.events = data;
                }
                
                this.yearRange = this.calculateYearRange();
                this.init();
                return;
            }
        } catch (error) {
            console.warn('Database API failed, falling back to XML:', error);
        }
        
        try {
            // Fallback to XML if database fails
            const response = await fetch('timeline/timeline.xml');
            const xmlText = await response.text();
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(xmlText, 'text/xml');
            
            this.events = this.parseXMLEvents(xmlDoc);
            this.yearRange = this.calculateYearRange();
            this.init();
        } catch (error) {
            console.error('Error loading timeline data:', error);
            // Fallback to sample data if both fail
            this.loadFallbackData();
            this.init();
        }
    }

    parseXMLEvents(xmlDoc) {
        const events = [];
        const eventNodes = xmlDoc.getElementsByTagName('event');
        
        for (let i = 0; i < eventNodes.length; i++) {
            const event = eventNodes[i];
            const startDate = event.getAttribute('start');
            const endDate = event.getAttribute('end');
            const title = event.getAttribute('title');
            const image = event.getAttribute('image');
            const link = event.getAttribute('link');
            
            if (startDate && title) {
                const eventText = event.textContent || '';
                const lines = eventText.trim().split('\n');
                let crew = '';
                let songList = '';
                
                for (let line of lines) {
                    line = line.trim();
                    if (line.startsWith('Crew:')) {
                        crew = line.substring(5).trim();
                    } else if (line.startsWith('Song List:')) {
                        songList = line.substring(10).trim();
                    }
                }
                
                // Generate ID from date
                const date = new Date(startDate);
                const id = date.getFullYear().toString() + 
                          (date.getMonth() + 1).toString().padStart(2, '0') + 
                          date.getDate().toString().padStart(2, '0');
                
                events.push({
                    id: id,
                    title: title,
                    start: startDate,
                    end: endDate || startDate,
                    crew: crew,
                    songList: songList,
                    image: image,
                    link: link
                });
            }
        }
        
        return events.sort((a, b) => new Date(a.start) - new Date(b.start));
    }

    loadFallbackData() {
        this.events = [
            {
                id: '19971230',
                title: 'Dec 30',
                start: '1997-12-30T18:00:00',
                end: '1997-12-30T21:00:00',
                crew: 'Stu, T-Bone, PeeTah, Haze, Maximus, Snuf',
                songList: 'Tribute, Blues, T-Bar Blues, FreeFall, Jam #47, A Love Supreme, Jerk Chicken, Insightful Crap, Blockhead, Fame, Gratuity',
                link: '/songs/19971230.mp3'
            },
            {
                id: '20240718',
                title: 'Jul 18',
                start: '2024-07-18T18:00:00',
                end: '2024-07-18T21:00:00',
                crew: 'Maximus, PChoff, Snuffler, Stu, Tbonk',
                songList: 'for a rose, dirty pot blues, aint supposed to be tn whiskey, musicus interruptus, i done a doobie, storm front, feelin alright, stu be good sweet home, you shook me, beast of a jane, werewolves of london, traction',
                link: '/video/StormPigs20240718.mp4'
            }
        ];
    }

    calculateYearRange() {
        if (this.events.length === 0) return { min: 1997, max: 2025 };
        
        const years = this.events.map(event => new Date(event.start).getFullYear());
        return {
            min: Math.min(...years),
            max: Math.max(...years)
        };
    }

    init() {
        this.render();
        this.setupEventListeners();
        this.centerTimeline();
    }

    render() {
        this.container.innerHTML = `
            <div class="modern-timeline-wrapper">
                <div class="timeline-controls">
                    <button id="zoom-out" class="zoom-btn">-</button>
                    <button id="zoom-in" class="zoom-btn">+</button>
                    <button id="fit-all" class="zoom-btn">Fit All</button>
                    <span id="zoom-level">Zoom: 63%</span>
                    <span class="event-count">${this.events.length} jams</span>
                </div>
                <div class="modern-timeline-container" id="timeline-container">
                    ${this.renderTimeline()}
                </div>
            </div>
        `;
    }

    getTrackWidth() {
        const yearSpan = this.yearRange.max - this.yearRange.min;
        const baseWidth = Math.max(2000, yearSpan * 100);
        return baseWidth * this.zoomLevel;
    }

    renderTimeline() {
        const timelineHtml = `
            <div class="modern-timeline-svg" id="timeline-track" style="width: ${this.getTrackWidth()}px">
                <div class="main-timeline-line"></div>
                ${this.renderVerticalMarkers()}
                ${this.renderEvents()}
            </div>
        `;
        
        return timelineHtml;
    }

    renderVerticalMarkers() {
        let markers = '';
        const yearSpan = this.yearRange.max - this.yearRange.min;
        
        // Determine interval based on zoom level
        let interval, showMonths = false;
        if (this.zoomLevel > 3) {
            interval = 1;
            showMonths = true;
        } else if (this.zoomLevel > 1.5) {
            interval = 1;
        } else {
            interval = 5;
        }
        
        for (let year = this.yearRange.min; year <= this.yearRange.max; year += interval) {
            const position = this.getYearPosition(year);
            markers += `
                <div class="vertical-marker year-marker" style="left: ${position}%">
                    <div class="marker-line"></div>
                    <div class="marker-label">${year}</div>
                </div>
            `;
            
            // Add month markers if zoomed in enough
            if (showMonths && year < this.yearRange.max) {
                for (let month = 1; month <= 12; month++) {
                    const monthPosition = this.getYearPosition(year + month / 12);
                    if (monthPosition < 100) {
                        markers += `
                            <div class="vertical-marker month-marker" style="left: ${monthPosition}%">
                                <div class="marker-line"></div>
                            </div>
                        `;
                    }
                }
            }
        }
        return markers;
    }

    renderEvents() {
        let eventsHtml = '';
        const eventsByPosition = this.groupEventsByPosition();
        
        Object.keys(eventsByPosition).forEach(positionKey => {
            const eventsAtPosition = eventsByPosition[positionKey];
            eventsAtPosition.forEach((event, index) => {
                const position = this.getEventPosition(event);
                const verticalOffset = this.calculateVerticalOffset(index, eventsAtPosition.length);
                const eventDate = new Date(event.start);
                
                // Determine edge positioning for cards that might go outside bounds
                let edgePosition = '';
                if (position < 10) {
                    edgePosition = 'left';
                } else if (position > 90) {
                    edgePosition = 'right';
                }

                eventsHtml += `
                    <div class="modern-timeline-event" 
                         style="left: ${position}%; top: 50%" 
                         data-event-id="${event.id}"
                         data-position="${edgePosition}">
                        <div class="modern-event-stickpin"></div>
                        <div class="modern-event-card" style="top: ${verticalOffset}px;">
                            <div class="modern-event-header">
                                <h3>${event.title}</h3>
                                <span class="modern-event-year">${eventDate.getFullYear()}</span>
                            </div>
                            <div class="modern-event-crew">${event.crew}</div>
                            ${event.link ? `
                                <div class="modern-event-actions">
                                    <button class="modern-play-button" data-link="${event.link}">
                                        ${event.link.includes('.mp4') ? '▶ Video' : '♪ Audio'}
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });
        });
        
        return eventsHtml;
    }

    groupEventsByPosition() {
        const grouped = {};
        const positionTolerance = 0.5; // Reduced tolerance - only group events very close together
        
        this.events.forEach(event => {
            const position = this.getEventPosition(event);
            const positionKey = Math.round(position / positionTolerance) * positionTolerance;
            
            if (!grouped[positionKey]) {
                grouped[positionKey] = [];
            }
            grouped[positionKey].push(event);
        });
        
        return grouped;
    }

    calculateVerticalOffset(index, totalAtPosition) {
        const cardHeight = 70;
        const minDistance = 10; // Minimum distance from timeline
        const maxDistance = 120; // Maximum distance from timeline center
        
        if (totalAtPosition === 1) {
            // Single event - place close to timeline, alternating sides
            return index % 2 === 0 ? -minDistance - cardHeight : minDistance;
        }
        
        // Multiple events - stack them with some overlap allowed
        const spacing = 25; // Reduced spacing to allow overlap
        
        if (index % 2 === 0) {
            // Even indices go above timeline
            const level = Math.floor(index / 2);
            const offset = -(minDistance + cardHeight + (level * spacing));
            return Math.max(offset, -maxDistance); // Clamp to bounds
        } else {
            // Odd indices go below timeline
            const level = Math.floor(index / 2);
            const offset = minDistance + (level * spacing);
            return Math.min(offset, maxDistance); // Clamp to bounds
        }
    }

    getEventPosition(event) {
        const totalYears = this.yearRange.max - this.yearRange.min;
        const eventDate = new Date(event.start);
        const eventYear = eventDate.getFullYear();
        const monthProgress = (eventDate.getMonth() + eventDate.getDate() / 31) / 12;
        const yearProgress = (eventYear - this.yearRange.min + monthProgress) / totalYears;
        return yearProgress * 100;
    }

    getYearPosition(year) {
        const totalYears = this.yearRange.max - this.yearRange.min;
        const yearProgress = (year - this.yearRange.min) / totalYears;
        return yearProgress * 100;
    }

    setupEventListeners() {
        // Remove existing listeners to prevent duplicates
        this.removeEventListeners();
        
        const container = document.getElementById('timeline-container');
        
        // Store bound functions for removal later
        this.boundZoomIn = () => this.zoom(1.5);
        this.boundZoomOut = () => this.zoom(0.67);
        this.boundFitAll = () => this.fitAll();
        this.boundMouseDown = (e) => {
            this.isDragging = true;
            this.dragMoved = false;
            this.startX = e.pageX - container.offsetLeft;
            this.scrollLeft = container.scrollLeft;
            container.style.cursor = 'grabbing';
        };
        this.boundMouseLeave = () => {
            this.isDragging = false;
            container.style.cursor = 'grab';
        };
        this.boundMouseUp = () => {
            this.isDragging = false;
            container.style.cursor = 'grab';
        };
        this.boundMouseMove = (e) => {
            if (!this.isDragging) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const walk = (x - this.startX) * 2;
            container.scrollLeft = this.scrollLeft - walk;
            if (Math.abs(walk) > 5) {
                this.dragMoved = true; // mark as a drag to suppress click
            }
        };
        this.boundWheel = (e) => {
            e.preventDefault();
            const zoomFactor = e.deltaY > 0 ? 0.9 : 1.1;
            this.zoom(zoomFactor);
        };
        this.boundTouchStart = (e) => {
            this.isDragging = true;
            this.dragMoved = false;
            this.startX = e.touches[0].pageX - container.offsetLeft;
            this.scrollLeft = container.scrollLeft;
        };
        this.boundTouchMove = (e) => {
            if (!this.isDragging) return;
            const x = e.touches[0].pageX - container.offsetLeft;
            const walk = (x - this.startX) * 2;
            container.scrollLeft = this.scrollLeft - walk;
            if (Math.abs(walk) > 5) {
                this.dragMoved = true;
            }
        };
        this.boundTouchEnd = () => {
            this.isDragging = false;
        };
        this.boundContainerClick = (e) => {
            // Prevent event bubbling for play buttons
            if (e.target.classList.contains('modern-play-button')) {
                e.stopPropagation();
                const link = e.target.getAttribute('data-link');
                window.open(link, '_blank');
                return;
            }

            // Suppress click if a drag just happened
            if (this.dragMoved) {
                // reset and ignore this click
                this.dragMoved = false;
                return;
            }

            const eventElement = e.target.closest('.modern-timeline-event');
            if (eventElement) {
                const eventId = eventElement.getAttribute('data-event-id');
                this.showEventDetail(eventId);
            }
        };
        this.boundCloseModal = () => this.closeModal();
        this.boundModalClick = (e) => {
            if (e.target.id === 'event-modal') {
                this.closeModal();
            }
        };
        this.boundEscKey = (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        };
        
        // Zoom controls
        document.getElementById('zoom-in').addEventListener('click', this.boundZoomIn);
        document.getElementById('zoom-out').addEventListener('click', this.boundZoomOut);
        document.getElementById('fit-all').addEventListener('click', this.boundFitAll);
        
        
        // Drag functionality
        container.addEventListener('mousedown', this.boundMouseDown);
        container.addEventListener('mouseleave', this.boundMouseLeave);
        container.addEventListener('mouseup', this.boundMouseUp);
        container.addEventListener('mousemove', this.boundMouseMove);

        // Mouse wheel zoom
        container.addEventListener('wheel', this.boundWheel);

        // Touch events
        container.addEventListener('touchstart', this.boundTouchStart);
        container.addEventListener('touchmove', this.boundTouchMove);
        container.addEventListener('touchend', this.boundTouchEnd);

        // Event clicks
        this.container.addEventListener('click', this.boundContainerClick);

        // Modal close
        document.getElementById('close-modal').addEventListener('click', this.boundCloseModal);
        document.getElementById('event-modal').addEventListener('click', this.boundModalClick);
        document.addEventListener('keydown', this.boundEscKey);
    }

    removeEventListeners() {
        if (!this.boundZoomIn) return; // First time setup
        
        const container = document.getElementById('timeline-container');
        
        // Remove zoom controls
        const zoomIn = document.getElementById('zoom-in');
        const zoomOut = document.getElementById('zoom-out');
        const fitAll = document.getElementById('fit-all');
        
        if (zoomIn) zoomIn.removeEventListener('click', this.boundZoomIn);
        if (zoomOut) zoomOut.removeEventListener('click', this.boundZoomOut);
        if (fitAll) fitAll.removeEventListener('click', this.boundFitAll);
        
        // Remove container events
        if (container) {
            container.removeEventListener('mousedown', this.boundMouseDown);
            container.removeEventListener('mouseleave', this.boundMouseLeave);
            container.removeEventListener('mouseup', this.boundMouseUp);
            container.removeEventListener('mousemove', this.boundMouseMove);
            container.removeEventListener('wheel', this.boundWheel);
            container.removeEventListener('touchstart', this.boundTouchStart);
            container.removeEventListener('touchmove', this.boundTouchMove);
            container.removeEventListener('touchend', this.boundTouchEnd);
        }

        // Remove main container click
        if (this.container) {
            this.container.removeEventListener('click', this.boundContainerClick);
        }

        // Remove modal events
        const closeModal = document.getElementById('close-modal');
        const eventModal = document.getElementById('event-modal');
        
        if (closeModal) closeModal.removeEventListener('click', this.boundCloseModal);
        if (eventModal) eventModal.removeEventListener('click', this.boundModalClick);
        document.removeEventListener('keydown', this.boundEscKey);
    }

    zoom(factor) {
        const oldZoom = this.zoomLevel;
        this.zoomLevel = Math.max(this.minZoom, Math.min(this.maxZoom, this.zoomLevel * factor));
        
        if (this.zoomLevel !== oldZoom) {
            const container = document.getElementById('timeline-container');
            const oldScrollLeft = container.scrollLeft;
            const oldScrollRatio = oldScrollLeft / (container.scrollWidth - container.clientWidth);
            
            this.render();
            this.setupEventListeners();
            
            // Maintain scroll position
            setTimeout(() => {
                const newScrollLeft = oldScrollRatio * (container.scrollWidth - container.clientWidth);
                container.scrollLeft = newScrollLeft;
            }, 10);
            
            document.getElementById('zoom-level').textContent = `Zoom: ${Math.round(this.zoomLevel * 100)}%`;
        }
    }

    fitAll() {
        this.zoomLevel = 0.63;
        this.render();
        this.setupEventListeners();
        this.centerTimeline();
        document.getElementById('zoom-level').textContent = `Zoom: ${Math.round(this.zoomLevel * 100)}%`;
    }

    showEventDetail(eventId) {
        // Avoid reopening if already visible; simply update content
        const modal = document.getElementById('event-modal');
        const isVisible = modal && getComputedStyle(modal).display !== 'none';
        const event = this.events.find(e => e.id === eventId);
        if (!event) return;

        const eventDate = new Date(event.start);
        const modalContent = document.getElementById('modal-content');
        
        modalContent.innerHTML = `
            <div class="modern-detail-container">
                ${event.image ? `
                    <div class="modern-detail-image-float">
                        <img src="${event.image}" alt="Jam ${event.title}" />
                    </div>
                ` : ''}
                <div class="modern-detail-header">
                    <h2>${event.title}, ${eventDate.getFullYear()}</h2>
                    <p class="modern-detail-date">${eventDate.toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'short', 
                        day: 'numeric' 
                    })}</p>
                </div>
                <div class="modern-detail-body">
                    <div class="modern-detail-section">
                        <h4>Crew:</h4>
                        <p>${event.crew}</p>
                    </div>
                    ${event.songList && event.songList !== 'n/a' ? `
                        <div class="modern-detail-section">
                            <h4>Song List:</h4>
                            <p class="modern-song-list">${event.songList}</p>
                        </div>
                    ` : ''}
                    ${event.link ? `
                        <div class="modern-detail-actions">
                            <button class="modern-detail-play-button" onclick="window.open('${event.link}', '_blank')">
                                ${event.link.includes('.mp4') ? '▶ Watch Video' : '♪ Listen to Audio'}
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;

        if (!isVisible) {
            document.getElementById('event-modal').style.display = 'flex';
        }
    }

    closeModal() {
        document.getElementById('event-modal').style.display = 'none';
    }

    navigateToYear(year) {
        const container = document.getElementById('timeline-container');
        const track = document.getElementById('timeline-track');
        if (container && track) {
            const yearPosition = this.getYearPosition(year);
            const scrollPosition = (yearPosition / 100) * track.scrollWidth - (container.clientWidth / 2);
            container.scrollLeft = Math.max(0, scrollPosition);
        }
    }

    centerTimeline() {
        setTimeout(() => {
            const container = document.getElementById('timeline-container');
            const track = document.getElementById('timeline-track');
            if (container && track) {
                const centerPosition = (track.scrollWidth - container.clientWidth) / 2;
                container.scrollLeft = centerPosition;
            }
        }, 100);
    }
}

// Initialize timeline when DOM is loaded
function initStormPigsTimeline() {
    new StormPigsTimeline('my-timeline');
}
