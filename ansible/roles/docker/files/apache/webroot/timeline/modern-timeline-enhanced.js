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
        // Debug helpers
        this.debugLog = [];
        this.verboseDebug = /timelineDebug=1/.test(window.location.search);
        this._programmaticScroll = false;
        // Browser detection (very limited: only to handle Safari scroll/anchor quirks)
        this.isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        this.centered = false; // becomes true after we programmatically center once or observe a scroll
        // Wheel-coalescing/lock to avoid oscillation on Safari
        this._wheelAccum = 1;
        this._wheelRAF = null;
        this._wheelLock = false;
        // Suppress scroll handler while applying programmatic multi-frame corrections
        this._suppressScroll = false;
        
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
        this.setupEventListeners(true);
        this.centerTimeline();
    }

    render() {
        this.container.innerHTML = `
            <div class="modern-timeline-wrapper">
                <div class="timeline-controls">
                    <button id="zoom-out" class="zoom-btn" type="button">-</button>
                    <button id="zoom-in" class="zoom-btn" type="button">+</button>
                    <button id="fit-all" class="zoom-btn" type="button">Fit All</button>
                    <span id="zoom-level">Zoom: 63%</span>
                    <span class="event-count">${this.events.length} jams</span>
                </div>
                <div class="modern-timeline-container" id="timeline-container">
                    ${this.renderTimeline()}
                </div>
                <div id="timeline-debug" style="font-family: monospace; font-size: 11px; color: #ccc; background:#111; border-top:1px solid #333; padding:6px 8px; line-height:1.4;"></div>
            </div>
        `;
    }

    // Map a content-space X (pixels along the track) back to a calendar year
    contentXToYear(contentX) {
        try {
            const tw = Math.max(1, this.getTrackWidth());
            const frac = Math.max(0, Math.min(1, contentX / tw));
            const spanYears = (this.yearRange.max - this.yearRange.min) + 1; // inclusive span
            return this.yearRange.min + frac * spanYears;
        } catch (e) { return null; }
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
            
            // Add month markers if zoomed in enough. For any year, compute months within that year
            // using (month-1)/12 so the last year's months are included as well.
            if (showMonths) {
                for (let month = 1; month <= 12; month++) {
                    const monthFrac = (month - 1) / 12; // 0..11/12 within the same calendar year
                    const monthPosition = this.getYearPosition(year + monthFrac);
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
        // Use an inclusive span so months within the max year don't push beyond 100%
        const totalSpanYears = (this.yearRange.max - this.yearRange.min) + 1;
        const eventDate = new Date(event.start);
        const eventYear = eventDate.getFullYear();
        const monthProgress = (eventDate.getMonth() + eventDate.getDate() / 31) / 12;
        const yearProgress = (eventYear - this.yearRange.min + monthProgress) / totalSpanYears;
        const pct = yearProgress * 100;
        // Clamp to keep events within the visible track
        return Math.max(0, Math.min(99.5, pct));
    }

    getYearPosition(year) {
        // Match the inclusive span used by events and clamp to stay inside the track
        const totalSpanYears = (this.yearRange.max - this.yearRange.min) + 1;
        const yearProgress = (year - this.yearRange.min) / totalSpanYears;
        const pct = yearProgress * 100;
        return Math.max(0, Math.min(99.9, pct));
    }

    setupEventListeners(initializeCenter = true) {
        // Remove existing listeners to prevent duplicates
        this.removeEventListeners();
        
        const container = document.getElementById('timeline-container');
        const zoomInEl = document.getElementById('zoom-in');
        const zoomOutEl = document.getElementById('zoom-out');
        const fitAllEl = document.getElementById('fit-all');

        // Retry binding if DOM not yet ready after render
        if (!container || !zoomInEl || !zoomOutEl || !fitAllEl) {
            this._bindRetries = (this._bindRetries || 0) + 1;
            if (this._bindRetries <= 5) {
                requestAnimationFrame(() => this.setupEventListeners(initializeCenter));
                return;
            }
            // Give up silently after retries to avoid crashing the page
        } else {
            this._bindRetries = 0;
        }
        
        // Store bound functions for removal later
        this.boundZoomIn = () => {
            if (this.modalOpen) return;
            const c = document.getElementById('timeline-container');
            if (c) {
                const vw = (c.clientWidth && c.clientWidth > 0) ? c.clientWidth : (c.getBoundingClientRect().width || 0);
                this.lastMouseX = Math.floor(vw / 2);
            }
            this.log('zoom-in-click');
            if (this.isSafari && !this.centered) {
                // Ensure we have a good center snapshot, then defer the zoom slightly
                const cc = document.getElementById('timeline-container');
                if (cc) {
                    const vw2 = (cc.clientWidth && cc.clientWidth > 0) ? cc.clientWidth : (cc.getBoundingClientRect().width || 0);
                    this.lastCenterContentX = cc.scrollLeft + Math.floor(vw2 / 2);
                }
                this.centered = true;
                setTimeout(() => this.zoomAt(1.5), 120);
                return;
            }
            this.zoomAt(1.5);
        };
        this.boundZoomOut = () => {
            if (this.modalOpen) return;
            const c = document.getElementById('timeline-container');
            if (c) {
                const vw = (c.clientWidth && c.clientWidth > 0) ? c.clientWidth : (c.getBoundingClientRect().width || 0);
                this.lastMouseX = Math.floor(vw / 2);
            }
            this.log('zoom-out-click');
            if (this.isSafari && !this.centered) {
                const cc = document.getElementById('timeline-container');
                if (cc) {
                    const vw2 = (cc.clientWidth && cc.clientWidth > 0) ? cc.clientWidth : (cc.getBoundingClientRect().width || 0);
                    this.lastCenterContentX = cc.scrollLeft + Math.floor(vw2 / 2);
                }
                this.centered = true;
                setTimeout(() => this.zoomAt(0.67), 120);
                return;
            }
            this.zoomAt(0.67);
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
            this.updateDebug('mousemove');
        };
        this.boundScroll = () => {
            // Track the content coordinate under the viewport center
            const centerX = Math.floor(container.clientWidth / 2);
            if (!this._suppressScroll) {
                this.lastCenterContentX = container.scrollLeft + centerX;
            }
            if (this._programmaticScroll) {
                this.log('scroll-programmatic', { scrollLeft: container.scrollLeft });
                // Clear guard after first observed programmatic scroll
                this._programmaticScroll = false;
            } else {
                this.log('scroll', { scrollLeft: container.scrollLeft });
            }
            this.updateDebug(this._programmaticScroll ? 'scroll-programmatic' : 'scroll');
        };
        this.boundWheel = (e) => {
            if (this.modalOpen) return;
            e.preventDefault();
            const zoomFactor = e.deltaY > 0 ? 0.9 : 1.1;
            const rect = container.getBoundingClientRect();
            const mouseX = e.clientX - rect.left; // relative to container viewport
            // Track last mouse position for anchoring
            const vw = (container.clientWidth && container.clientWidth > 0) ? container.clientWidth : (rect.width || 0);
            // On Safari, anchor wheel zoom to viewport center to avoid accidental edge anchoring
            if (this.isSafari) {
                this.lastMouseX = Math.floor(vw / 2);
            } else {
                this.lastMouseX = Math.max(0, Math.min(vw, Math.floor(mouseX)));
            }
            const preCenterContentX = container.scrollLeft + Math.floor(vw / 2);
            const preCenterYear = this.contentXToYear(preCenterContentX);
            this.log('wheel', JSON.stringify({ zoomFactor, mouseX, sl: container.scrollLeft, preCenterContentX, preCenterYear, centered: this.centered }));
            // If Safari hasn't centered/settled yet, defer the first wheel zoom slightly
            if (this.isSafari && !this.centered) {
                // Snapshot a reasonable center anchor and mark centered
                this.lastCenterContentX = container.scrollLeft + Math.floor(vw / 2);
                this.centered = true;
                setTimeout(() => this.zoomAt(zoomFactor, this.lastMouseX), 120);
                return;
            }
            // If a zoom is currently being applied, skip this wheel to avoid ping-ponging
            if (this._wheelLock) {
                return;
            }
            // Coalesce multiple wheel events into one zoom per frame
            this._wheelAccum *= zoomFactor;
            if (!this._wheelRAF) {
                this._wheelRAF = requestAnimationFrame(() => {
                    const factor = this._wheelAccum;
                    this._wheelAccum = 1;
                    this._wheelRAF = null;
                    this._wheelLock = true;
                    this.zoomAt(factor, this.lastMouseX);
                    // unlock shortly after to allow next gesture without oscillation
                    setTimeout(() => { this._wheelLock = false; }, this.isSafari ? 160 : 80);
                    // reflect status soon after
                    setTimeout(() => this.updateDebug('wheel'), 0);
                });
            }
        };
        // Keep anchoring stable when layout/viewport changes (e.g., scrollbar show/hide on modal)
        this.boundResize = () => {
            const c = document.getElementById('timeline-container');
            if (!c) return;
            const r = c.getBoundingClientRect();
            const vw = (c.clientWidth && c.clientWidth > 0) ? c.clientWidth : (r.width || 0);
            const center = Math.max(0, Math.floor(vw / 2));
            this.lastMouseX = center;
            this.lastCenterContentX = c.scrollLeft + center;
            this.updateDebug('resize');
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

            // Ignore clicks originating from the controls toolbar
            if (e.target.closest('.timeline-controls')) {
                return;
            }

            // Suppress click if a drag just happened
            if (this.dragMoved) {
                // reset and ignore this click
                this.dragMoved = false;
                return;
            }

            // On Safari, do NOT update lastMouseX from clicks to avoid accidental left-edge anchor
            const cont = document.getElementById('timeline-container');
            if (cont) {
                const r = cont.getBoundingClientRect();
                const vw = (cont.clientWidth && cont.clientWidth > 0) ? cont.clientWidth : (r.width || 0);
                if (this.isSafari) {
                    if (vw > 0) this.lastMouseX = Math.floor(vw / 2);
                } else {
                    const relX = e.clientX - r.left;
                    if (isFinite(relX)) {
                        // Clamp within current viewport width
                        const widthForClamp = vw > 0 ? vw : 1;
                        this.lastMouseX = Math.max(0, Math.min(widthForClamp, relX));
                    }
                }
            }

            const eventElement = e.target.closest('.modern-timeline-event');
            if (eventElement) {
                const eventId = String(eventElement.getAttribute('data-event-id'));
                this.showEventDetail(eventId);
            }
            this.updateDebug('click');
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
        
        // Zoom controls (wrap to stop propagation so container click handler doesn't capture)
        this.boundZoomInClick = (e) => { e.preventDefault(); e.stopPropagation(); this.boundZoomIn(); };
        this.boundZoomOutClick = (e) => { e.preventDefault(); e.stopPropagation(); this.boundZoomOut(); };
        this.boundFitAllClick = (e) => { e.preventDefault(); e.stopPropagation(); this.boundFitAll(); };
        if (zoomInEl) zoomInEl.addEventListener('click', this.boundZoomInClick);
        if (zoomOutEl) zoomOutEl.addEventListener('click', this.boundZoomOutClick);
        if (fitAllEl) fitAllEl.addEventListener('click', this.boundFitAllClick);
        
        
        // Drag functionality
        container.addEventListener('mousedown', this.boundMouseDown);
        container.addEventListener('mouseleave', this.boundMouseLeave);
        container.addEventListener('mouseup', this.boundMouseUp);
        container.addEventListener('mousemove', this.boundMouseMove);

        // Mouse wheel zoom
        // Wheel: explicitly set passive:false so preventDefault works reliably in WebKit
        container.addEventListener('wheel', this.boundWheel, { passive: false });
        // Track scroll position continuously
        container.addEventListener('scroll', this.boundScroll);
        // Initialize center snapshot (optional to avoid clobbering during programmatic zoom scroll set)
        if (initializeCenter) {
            this.boundScroll();
        }

        // Window resize listener (fires when body scrollbar appears/disappears on modal)
        window.addEventListener('resize', this.boundResize);

        // Touch events
        container.addEventListener('touchstart', this.boundTouchStart);
        container.addEventListener('touchmove', this.boundTouchMove);
        container.addEventListener('touchend', this.boundTouchEnd);

        // Event clicks
        this.container.addEventListener('click', this.boundContainerClick);

        // Modal close
        const closeBtn = document.getElementById('close-modal');
        const modalEl = document.getElementById('event-modal');
        if (closeBtn) closeBtn.addEventListener('click', this.boundCloseModal);
        if (modalEl) modalEl.addEventListener('click', this.boundModalClick);
        document.addEventListener('keydown', this.boundEscKey);

        // Initial debug snapshot
        this.updateDebug('listeners-attached');
    }

    removeEventListeners() {
        if (!this.boundZoomIn) return; // First time setup
        
        const container = document.getElementById('timeline-container');
        
        // Remove zoom controls
        const zoomIn = document.getElementById('zoom-in');
        const zoomOut = document.getElementById('zoom-out');
        const fitAll = document.getElementById('fit-all');
        
        if (zoomIn) zoomIn.removeEventListener('click', this.boundZoomInClick);
        if (zoomOut) zoomOut.removeEventListener('click', this.boundZoomOutClick);
        if (fitAll) fitAll.removeEventListener('click', this.boundFitAllClick);
        
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
        window.removeEventListener('resize', this.boundResize);
    }

    zoom(factor) {
        const container = document.getElementById('timeline-container');
        if (!container) return;
        const rect = container.getBoundingClientRect();
        const vwFallback = (typeof window !== 'undefined') ? (window.innerWidth || 0) : 0;
        const viewportWidth = Math.max(1, Math.floor(
            (container.clientWidth && container.clientWidth > 0)
                ? container.clientWidth
                : (rect && rect.width && rect.width > 0 ? rect.width : (this._savedClientWidth || vwFallback || 1))
        ));
        let anchorX;
        if (this.lastMouseX !== null && this.lastMouseX >= 0 && this.lastMouseX <= viewportWidth) {
            anchorX = this.lastMouseX;
        } else if (typeof this.lastCenterContentX === 'number') {
            // Map stored content center back to current viewport X
            anchorX = Math.max(0, Math.min(viewportWidth, this.lastCenterContentX - container.scrollLeft));
        } else {
            anchorX = Math.floor(viewportWidth / 2);
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

        // Derive a reliable viewport width (clientWidth can be 0 briefly after modal open/close)
        const rect = container.getBoundingClientRect();
        const vwFallback = (typeof window !== 'undefined') ? (window.innerWidth || 0) : 0;
        const viewportWidth = Math.max(1, Math.floor(
            (container.clientWidth && container.clientWidth > 0)
                ? container.clientWidth
                : (rect && rect.width && rect.width > 0
                    ? rect.width
                    : (this._savedClientWidth || vwFallback || 1))
        ));

        // Establish a robust anchor X within the current viewport
        let computedAnchor = anchorViewportX;
        if (computedAnchor === undefined || computedAnchor === null || !isFinite(computedAnchor)) {
            // Try last mouse, then map saved center content to current viewport, else center
            if (this.lastMouseX !== null && this.lastMouseX >= 0 && this.lastMouseX <= viewportWidth) {
                computedAnchor = this.lastMouseX;
            } else if (typeof this.lastCenterContentX === 'number') {
                computedAnchor = Math.max(0, Math.min(viewportWidth, this.lastCenterContentX - container.scrollLeft));
            } else {
                computedAnchor = Math.floor(viewportWidth / 2);
            }
        }
        // Clamp anchor within the (possibly corrected) viewport width
        const _anchorX = Math.max(0, Math.min(viewportWidth, computedAnchor));
        // Derive content anchor from current scrollLeft.
        // Safari sometimes reports transient scrollLeft=0 during the first zooms.
        // Prefer the last known center content coordinate when available.
        const anchorContentX = (this.isSafari && typeof this.lastCenterContentX === 'number')
            ? this.lastCenterContentX
            : (container.scrollLeft + _anchorX); // content coord under cursor

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
            // Re-derive viewport width in case it changed after render
            const postRect = baseContainer.getBoundingClientRect();
            const postVwFallback = (typeof window !== 'undefined') ? (window.innerWidth || 0) : 0;
            const postViewportWidth = Math.max(1, Math.floor(
                (baseContainer.clientWidth && baseContainer.clientWidth > 0)
                    ? baseContainer.clientWidth
                    : (postRect && postRect.width && postRect.width > 0 ? postRect.width : (postVwFallback || viewportWidth))
            ));
            const maxScroll = Math.max(0, newContentWidth - postViewportWidth);
            const target = newAnchorContentX - Math.max(0, Math.min(postViewportWidth, _anchorX));
            const newScrollLeft = Math.max(0, Math.min(maxScroll, target));
            // Set scroll before anything else to avoid transient 0 scrollLeft reads
            this._suppressScroll = true;
            baseContainer.scrollLeft = newScrollLeft;
            const label = document.getElementById('zoom-level');
            if (label) label.textContent = `Zoom: ${Math.round(this.zoomLevel * 100)}%`;
            // Refresh center snapshot
            const finalViewportWidth = (baseContainer.clientWidth && baseContainer.clientWidth > 0)
                ? baseContainer.clientWidth
                : postViewportWidth;
            const preYear = this.contentXToYear(anchorContentX);
            this.lastCenterContentX = newScrollLeft + Math.floor(finalViewportWidth / 2);
            const postYear = this.contentXToYear(this.lastCenterContentX);
            this.log('zoomAt-debug', JSON.stringify({
                isSafari: this.isSafari,
                anchorViewportX: _anchorX,
                anchorContentX,
                preYear,
                ratio,
                newAnchorContentX,
                newScrollLeft,
                finalViewportWidth,
                postYear
            }));
            this.updateDebug('zoomAt');
            // Safari second-frame correction: some scrollLeft sets are ignored on first frame
            if (this.isSafari) {
                requestAnimationFrame(() => {
                    const c2 = document.getElementById('timeline-container') || baseContainer;
                    const max2 = Math.max(0, this.getTrackWidth() - ((c2.clientWidth && c2.clientWidth>0)?c2.clientWidth:c2.getBoundingClientRect().width||0));
                    const target2 = Math.max(0, Math.min(max2, newAnchorContentX - Math.max(0, Math.min(postViewportWidth, _anchorX))));
                    c2.scrollLeft = target2;
                    this.lastCenterContentX = target2 + Math.floor(((c2.clientWidth && c2.clientWidth>0)?c2.clientWidth:c2.getBoundingClientRect().width||0) / 2);
                    const post2Year = this.contentXToYear(this.lastCenterContentX);
                    this.log('zoomAt-2nd-frame-debug', JSON.stringify({ target2, post2Year }));
                    this.updateDebug('zoomAt-2nd-frame');
                    // Third-frame correction: re-apply intended mapping in case frame 2 was also ignored
                    requestAnimationFrame(() => {
                        const c3 = document.getElementById('timeline-container') || baseContainer;
                        const rect3 = c3.getBoundingClientRect();
                        const vw3 = (c3.clientWidth && c3.clientWidth>0) ? c3.clientWidth : (rect3.width || postViewportWidth);
                        const max3 = Math.max(0, this.getTrackWidth() - vw3);
                        const desiredScroll = Math.max(0, Math.min(max3, newAnchorContentX - Math.max(0, Math.min(vw3, _anchorX))));
                        c3.scrollLeft = desiredScroll;
                        this.lastCenterContentX = desiredScroll + Math.floor(vw3 / 2);
                        this.log('zoomAt-3rd-frame-debug', JSON.stringify({ desiredScroll, vw3 }));
                        this.updateDebug('zoomAt-3rd-frame');
                        // Release scroll suppression after a short settle to avoid oscillation
                        setTimeout(() => { this._suppressScroll = false; }, 60);
                    });
                });
            } else {
                // Non-Safari: release suppression immediately next task
                setTimeout(() => { this._suppressScroll = false; }, 0);
            }
        });
    }

    fitAll() {
        this.zoomLevel = 0.63;
        this.render();
        this.setupEventListeners();
        this.centerTimeline();
        document.getElementById('zoom-level').textContent = `Zoom: ${Math.round(this.zoomLevel * 100)}%`;
        this.updateDebug('fitAll');
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
        // Save latest mouse anchor so we can preserve it across modal interactions
        this._savedLastMouseX = (this.lastMouseX !== null && isFinite(this.lastMouseX)) ? this.lastMouseX : null;

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
            // Lock body scroll to avoid layout shifts and scrollbar disappearance changing widths
            this._bodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.log('modal-open', { scBefore, lastCenterContentX: this.lastCenterContentX });
            document.getElementById('event-modal').style.display = 'flex';
            // Aggressively enforce pre-open scroll position for a few frames/ms to avoid left jump
            let frames = 6; // ~6 frames (~100ms)
            const enforceRAF = () => {
                if (frames-- <= 0) return;
                const c = document.getElementById('timeline-container');
                if (c) c.scrollLeft = scBefore;
                requestAnimationFrame(enforceRAF);
            };
            requestAnimationFrame(enforceRAF);
            // Timed guards to catch async layout/scrollbar adjustments
            const times = [0, 30, 60, 120, 200, 300];
            times.forEach(t => setTimeout(() => {
                const cc = document.getElementById('timeline-container');
                if (cc) cc.scrollLeft = scBefore;
            }, t));
            this.updateDebug('modal-open');
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
            this.setScrollLeft(container, leftMapped, 'modal-close-leftMapped');
            // Aggressive multi-frame and timed enforcement after close to stop left jump
            let centerMapped;
            const enforceAfterClose = () => {
                const c = document.getElementById('timeline-container');
                if (!c) return;
                this.setScrollLeft(c, leftMapped, 'modal-close-raf-leftMapped');
                // Secondary restore (center-based) as a fallback if needed
                if (typeof this._savedCenterContentX === 'number') {
                    const newCenterContentX = this._savedCenterContentX * ratio;
                    centerMapped = Math.max(0, Math.min(newWidth - c.clientWidth, newCenterContentX - Math.floor(c.clientWidth / 2)));
                    this.setScrollLeft(c, centerMapped, 'modal-close-raf-centerMapped');
                    this.lastCenterContentX = newCenterContentX;
                }
            };
            let frames = 8; // ~8 frames
            const rafLoop = () => {
                if (frames-- <= 0) return;
                enforceAfterClose();
                requestAnimationFrame(rafLoop);
            };
            requestAnimationFrame(rafLoop);
            [0, 30, 60, 120, 200, 300].forEach(t => setTimeout(() => {
                const cc = document.getElementById('timeline-container');
                if (cc) this.setScrollLeft(cc, (centerMapped ?? leftMapped), 'modal-close-timeout');
            }, t));
            // Restore lastMouseX anchor if we saved one
            if (this._savedLastMouseX !== null) {
                const vw = (container.clientWidth && container.clientWidth > 0) ? container.clientWidth : container.getBoundingClientRect().width;
                this.lastMouseX = Math.max(0, Math.min(vw, this._savedLastMouseX));
            }
            this.modalOpen = false;
            // Unlock body scroll
            document.body.style.overflow = (typeof this._bodyOverflow === 'string') ? this._bodyOverflow : '';
            // Ensure snapshot reflects DOM after close
            this.boundScroll && this.boundScroll();
            // Immediately set anchor to viewport center so button clicks right after close stay centered
            const cNow = document.getElementById('timeline-container');
            if (cNow) {
                const vwNow = (cNow.clientWidth && cNow.clientWidth > 0) ? cNow.clientWidth : (cNow.getBoundingClientRect().width || 0);
                if (vwNow > 0) {
                    this.lastMouseX = Math.floor(vwNow / 2);
                    this.lastCenterContentX = cNow.scrollLeft + Math.floor(vwNow / 2);
                    this.log('post-close-center-set', { lastMouseX: this.lastMouseX, lastCenterContentX: this.lastCenterContentX, scrollLeft: cNow.scrollLeft });
                }
            }
            // After a short delay (allowing layout to settle), set lastMouseX to viewport center
            setTimeout(() => {
                const c2 = document.getElementById('timeline-container');
                if (!c2) return;
                const vw2 = (c2.clientWidth && c2.clientWidth > 0) ? c2.clientWidth : (c2.getBoundingClientRect().width || 0);
                if (vw2 > 0) {
                    this.lastMouseX = Math.floor(vw2 / 2);
                    // Refresh center snapshot again
                    this.lastCenterContentX = c2.scrollLeft + Math.floor(vw2 / 2);
                    this.updateDebug('post-close-center-anchor');
                }
            }, 50);
            this.updateDebug('modal-close');
        }
    }

    updateDebug(reason = '') {
        try {
            // Only update the on-page debug panel when explicitly enabled via ?timelineDebug=1
            if (!this.verboseDebug) return;
            const dbg = document.getElementById('timeline-debug');
            const container = document.getElementById('timeline-container');
            const track = document.getElementById('timeline-track');
            if (!container) return;
            const rect = container.getBoundingClientRect();
            const cw = container.clientWidth || rect.width || 0;
            const ch = container.clientHeight || rect.height || 0;
            const tw = this.getTrackWidth();
            const scrollLeft = container.scrollLeft;
            const lastMouseX = this.lastMouseX;
            const lastCenterContentX = this.lastCenterContentX;
            const savedCenter = this._savedCenterContentX;
            const savedLeftExact = this._savedScrollLeftExact;
            const savedClient = this._savedClientWidth;
            const modalOpen = this.modalOpen;
            const zoom = this.zoomLevel;
            const payload = {
                reason,
                zoomLevel: Number(zoom.toFixed(3)),
                container: { clientWidth: Math.round(cw), clientHeight: Math.round(ch), scrollLeft: Math.round(scrollLeft) },
                trackWidth: Math.round(tw),
                lastMouseX: lastMouseX !== null ? Math.round(lastMouseX) : null,
                lastCenterContentX: typeof lastCenterContentX === 'number' ? Math.round(lastCenterContentX) : null,
                _savedCenterContentX: typeof savedCenter === 'number' ? Math.round(savedCenter) : null,
                _savedScrollLeftExact: typeof savedLeftExact === 'number' ? Math.round(savedLeftExact) : null,
                _savedClientWidth: typeof savedClient === 'number' ? Math.round(savedClient) : null,
                modalOpen,
                isSafari: !!this.isSafari,
                centered: !!this.centered
            };
            if (dbg) {
                dbg.innerHTML = `
                <div><strong>reason</strong>: ${payload.reason}</div>
                <div><strong>zoomLevel</strong>: ${payload.zoomLevel}</div>
                <div><strong>container</strong>: { clientWidth: ${payload.container.clientWidth}, clientHeight: ${payload.container.clientHeight}, scrollLeft: ${payload.container.scrollLeft} }</div>
                <div><strong>trackWidth</strong>: ${payload.trackWidth}</div>
                <div><strong>lastMouseX</strong>: ${payload.lastMouseX !== null ? payload.lastMouseX : 'null'}</div>
                <div><strong>lastCenterContentX</strong>: ${payload.lastCenterContentX !== null ? payload.lastCenterContentX : 'null'}</div>
                <div><strong>_savedCenterContentX</strong>: ${payload._savedCenterContentX !== null ? payload._savedCenterContentX : 'null'}</div>
                <div><strong>_savedScrollLeftExact</strong>: ${payload._savedScrollLeftExact !== null ? payload._savedScrollLeftExact : 'null'}</div>
                <div><strong>_savedClientWidth</strong>: ${payload._savedClientWidth !== null ? payload._savedClientWidth : 'null'}</div>
                <div><strong>modalOpen</strong>: ${payload.modalOpen}</div>
                <div><strong>recent</strong> (last 6):<br>${this.debugLog.slice(-6).map(r => `${r.t} ${r.r} sl=${r.sl}`).join('<br>')}</div>
                `;
            } else {
                // Fallback to console if the debug container isn't available
                console.log('[timeline-debug]', payload);
            }
        } catch (e) {
            // no-op for debug
        }
    }

    // Utility: set scrollLeft with programmatic guard and logging
    setScrollLeft(el, value, tag = 'setScrollLeft') {
        this._programmaticScroll = true;
        el.scrollLeft = value;
        this.log(tag, { scrollLeft: value });
    }

    // Utility: append to rolling debug log and optionally console.log
    log(reason, extra = {}) {
        const ts = new Date();
        const t = ts.toLocaleTimeString('en-US', { hour12: false }) + '.' + String(ts.getMilliseconds()).padStart(3, '0');
        const container = document.getElementById('timeline-container');
        const sl = container ? container.scrollLeft : null;
        // Flatten extra if it's a JSON string, to keep Safari console readable on one line
        let payload;
        if (typeof extra === 'string') {
            payload = { str: extra };
        } else {
            payload = extra;
        }
        this.debugLog.push({ t, r: reason, sl, ...payload });
        if (this.debugLog.length > 100) this.debugLog.shift();
        if (this.verboseDebug) {
            // Pretty console output
            // eslint-disable-next-line no-console
            console.log('[tl]', t, reason, { sl, ...payload });
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
                // Mark as centered and capture center snapshot for anchoring
                this.centered = true;
                const vw = (container.clientWidth && container.clientWidth > 0) ? container.clientWidth : (container.getBoundingClientRect().width || 0);
                this.lastCenterContentX = container.scrollLeft + Math.floor(vw / 2);
            }
        }, 100);
    }
}

// Initialize timeline when DOM is loaded
function initStormPigsTimeline() {
    new StormPigsTimeline('my-timeline');
}
