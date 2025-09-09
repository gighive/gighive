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
        this.lastMouseX = null; // last known mouse X within container viewport
        this.lastCenterContentX = null; // last known content coordinate at viewport center
        this._savedScrollExact = null;   // exact scrollLeft snapshot around modal
        this.modalOpen = false;          // prevent interactions while modal is open
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
                    // Normalize IDs to strings to avoid strict-equality mismatches on click
                    this.events = data.events.map(ev => ({
                        ...ev,
                        id: String(ev.id)
                    }));
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
        this.boundZoomIn = () => {
            if (this.modalOpen) return;
            const containerEl = document.getElementById('timeline-container');
            const centerX = containerEl ? Math.floor(containerEl.clientWidth / 2) : 0;
            const anchorX = (this.lastMouseX !== null && containerEl && this.lastMouseX >= 0 && this.lastMouseX <= containerEl.clientWidth)
                ? this.lastMouseX : centerX;
            this.zoomAt(1.5, anchorX);
        };
        this.boundZoomOut = () => {
            if (this.modalOpen) return;
            const containerEl = document.getElementById('timeline-container');
            const centerX = containerEl ? Math.floor(containerEl.clientWidth / 2) : 0;
            const anchorX = (this.lastMouseX !== null && containerEl && this.lastMouseX >= 0 && this.lastMouseX <= containerEl.clientWidth)
                ? this.lastMouseX : centerX;
            this.zoomAt(0.67, anchorX);
        };
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
            const rect = container.getBoundingClientRect();
            this.lastMouseX = e.clientX - rect.left; // track last mouse position in viewport coords
            if (!this.isDragging) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const walk = (x - this.startX) * 2;
            container.scrollLeft = this.scrollLeft - walk;
            if (Math.abs(walk) > 5) {
                this.dragMoved = true; // mark as a drag to suppress click
            }
        };
        this.boundScroll = () => {
            // Track the content coordinate under the viewport center
            const centerX = Math.floor(container.clientWidth / 2);
            this.lastCenterContentX = container.scrollLeft + centerX;
        };
        this.boundWheel = (e) => {
            if (this.modalOpen) return;
            e.preventDefault();
            const zoomFactor = e.deltaY > 0 ? 0.9 : 1.1;
            const rect = container.getBoundingClientRect();
            const mouseX = e.clientX - rect.left; // relative to container viewport
            this.zoomAt(zoomFactor, mouseX);
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
                const eventId = String(eventElement.getAttribute('data-event-id'));
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
        // Track scroll position continuously
        container.addEventListener('scroll', this.boundScroll);
        // Initialize center snapshot
        this.boundScroll();

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
        const container = document.getElementById('timeline-container');
        if (!container) return;
        let anchorX;
        if (this.lastMouseX !== null && this.lastMouseX >= 0 && this.lastMouseX <= container.clientWidth) {
            anchorX = this.lastMouseX;
        } else if (typeof this.lastCenterContentX === 'number') {
            // Map stored content center back to current viewport X
            anchorX = Math.max(0, Math.min(container.clientWidth, this.lastCenterContentX - container.scrollLeft));
        } else {
            anchorX = Math.floor(container.clientWidth / 2);
        }
        this.zoomAt(factor, anchorX);
    }

    zoomAt(factor, anchorViewportX) {
        const container = document.getElementById('timeline-container');
        const track = document.getElementById('timeline-track');
        if (!container) return;

        const oldZoom = this.zoomLevel;
        // Use virtual width to avoid DOM timing issues
        const oldContentWidth = this.getTrackWidth();
        // Clamp anchor within viewport
        const _anchorX = Math.max(0, Math.min(container.clientWidth, anchorViewportX));
        const anchorContentX = container.scrollLeft + _anchorX; // content coord under cursor

        this.zoomLevel = Math.max(this.minZoom, Math.min(this.maxZoom, this.zoomLevel * factor));
        if (this.zoomLevel === oldZoom) return;

        this.render();
        this.setupEventListeners();

        // After re-render, compute new scrollLeft so that the same content point stays under the cursor
        // Use rAF to wait for layout to settle
        requestAnimationFrame(() => {
            const newContainer = document.getElementById('timeline-container');
            const newTrack = document.getElementById('timeline-track');
            const baseContainer = newContainer || container; // fallback just in case
            const newContentWidth = this.getTrackWidth();
            const safeOldWidth = Math.max(1, oldContentWidth);
            const ratio = Math.max(0.0001, newContentWidth / safeOldWidth);
            const newAnchorContentX = anchorContentX * ratio;
            const maxScroll = Math.max(0, newContentWidth - baseContainer.clientWidth);
            const target = newAnchorContentX - _anchorX;
            const newScrollLeft = Math.max(0, Math.min(maxScroll, target));
            baseContainer.scrollLeft = newScrollLeft;
            const label = document.getElementById('zoom-level');
            if (label) label.textContent = `Zoom: ${Math.round(this.zoomLevel * 100)}%`;
            // Refresh center snapshot
            this.lastCenterContentX = newScrollLeft + Math.floor(baseContainer.clientWidth / 2);
        });
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

        // Save current viewport center content coordinate and virtual width
        const container = document.getElementById('timeline-container');
        const centerX = container ? Math.floor(container.clientWidth / 2) : 0;
        this._savedCenterContentX = container ? (container.scrollLeft + centerX) : 0;
        this._savedTrackWidth = this.getTrackWidth();
        // Also save exact left-edge alignment so we can restore 1:1
        this._savedScrollLeftExact = container ? container.scrollLeft : 0;
        this._savedClientWidth = container ? container.clientWidth : 0;

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
                                ${event.link.includes('.mp4') ? '▶ Video Snippet' : '♪ Listen to Audio'}
                            </button>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;

        if (!isVisible) {
            const scBefore = container ? container.scrollLeft : 0;
            this.modalOpen = true;
            document.getElementById('event-modal').style.display = 'flex';
            // Final guard: restore exact scroll on next frame in case layout shifted
            requestAnimationFrame(() => {
                const c = document.getElementById('timeline-container');
                if (c) c.scrollLeft = scBefore;
                // Double-guard with timers for Chrome
                setTimeout(()=>{ const cc=document.getElementById('timeline-container'); if(cc) cc.scrollLeft=scBefore; }, 0);
                setTimeout(()=>{ const cc=document.getElementById('timeline-container'); if(cc) cc.scrollLeft=scBefore; }, 50);
            });
        }
    }

    closeModal() {
        document.getElementById('event-modal').style.display = 'none';
        // Restore viewport so the same content point remains centered
        const container = document.getElementById('timeline-container');
        if (container && typeof this._savedTrackWidth === 'number') {
            const newWidth = this.getTrackWidth();
            const ratio = Math.max(0.0001, newWidth / Math.max(1, this._savedTrackWidth));
            // Primary restore: left-edge mapping
            const leftMapped = Math.max(0, Math.min(newWidth - container.clientWidth, this._savedScrollLeftExact * ratio));
            container.scrollLeft = leftMapped;
            requestAnimationFrame(() => {
                const c = document.getElementById('timeline-container');
                if (c) c.scrollLeft = leftMapped;
                // Secondary restore (center-based) as a fallback if needed
                if (typeof this._savedCenterContentX === 'number') {
                    const newCenterContentX = this._savedCenterContentX * ratio;
                    const centerMapped = Math.max(0, Math.min(newWidth - c.clientWidth, newCenterContentX - Math.floor(c.clientWidth / 2)));
                    c.scrollLeft = centerMapped;
                    requestAnimationFrame(()=>{ if (c) c.scrollLeft = centerMapped; });
                    this.lastCenterContentX = newCenterContentX;
                }
                // Extra guards
                setTimeout(()=>{ const cc=document.getElementById('timeline-container'); if(cc) cc.scrollLeft = centerMapped ?? leftMapped; }, 0);
                setTimeout(()=>{ const cc=document.getElementById('timeline-container'); if(cc) cc.scrollLeft = centerMapped ?? leftMapped; }, 50);
                this.modalOpen = false;
            });
            // Ensure snapshot reflects DOM after close
            this.boundScroll && this.boundScroll();
        }
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
