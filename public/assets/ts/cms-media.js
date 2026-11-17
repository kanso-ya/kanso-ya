/**
 * @file cms-media.ts
 * @description Helpers interactifs pour les composants médias riches du CMS Kanso-ya :
 * - Lecteur vidéo léger personnalisé (Play/Pause, Stop, Volume) sans barre native
 * - Carrousel photos (Horizontal & Vertical) avec flèches de navigation et indicateur de slide.
 */
export function initCmsMediaInteractions(root) {
    if (!root)
        return;
    // 1. Initialisation des lecteurs vidéos légers personnalisés
    root.querySelectorAll('.custom-video-player').forEach((player) => {
        if (player.dataset.initialized === '1')
            return;
        player.dataset.initialized = '1';
        const video = player.querySelector('video');
        const playBtn = player.querySelector('.btn-video-play');
        const stopBtn = player.querySelector('.btn-video-stop');
        const muteBtn = player.querySelector('.btn-video-mute');
        const volumeSlider = player.querySelector('.video-volume-slider');
        if (!video)
            return;
        const updatePlayState = () => {
            if (playBtn) {
                playBtn.textContent = video.paused ? '▶ Play' : '⏸ Pause';
            }
        };
        playBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            if (video.paused) {
                video.play().catch(() => { });
            }
            else {
                video.pause();
            }
            updatePlayState();
        });
        stopBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            video.pause();
            video.currentTime = 0;
            updatePlayState();
        });
        video.addEventListener('play', updatePlayState);
        video.addEventListener('pause', updatePlayState);
        video.addEventListener('ended', updatePlayState);
        volumeSlider?.addEventListener('input', (e) => {
            e.stopPropagation();
            const vol = parseFloat(volumeSlider.value);
            video.volume = vol;
            video.muted = vol === 0;
            if (muteBtn) {
                muteBtn.textContent = vol === 0 ? '🔇' : (vol < 0.5 ? '🔈' : '🔊');
            }
        });
        muteBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            video.muted = !video.muted;
            if (volumeSlider) {
                volumeSlider.value = video.muted ? '0' : String(video.volume || 1);
            }
            muteBtn.textContent = video.muted ? '🔇' : (video.volume < 0.5 ? '🔈' : '🔊');
        });
    });
    // 2. Initialisation des carrousels photos (horizontal & vertical)
    root.querySelectorAll('.cms-carousel').forEach((carousel) => {
        if (carousel.dataset.initialized === '1')
            return;
        carousel.dataset.initialized = '1';
        const track = carousel.querySelector('.carousel-track');
        const slides = carousel.querySelectorAll('.carousel-slide');
        const prevBtn = carousel.querySelector('.btn-carousel-prev');
        const nextBtn = carousel.querySelector('.btn-carousel-next');
        const indicator = carousel.querySelector('.carousel-indicator');
        const isVertical = carousel.classList.contains('cms-carousel-vertical') || carousel.dataset.carouselOrientation === 'vertical';
        if (!track || slides.length === 0)
            return;
        let currentIndex = parseInt(carousel.getAttribute('data-current-slide') || '0', 10);
        if (isNaN(currentIndex) || currentIndex < 0 || currentIndex >= slides.length) {
            currentIndex = 0;
        }
        const updateCarousel = (index) => {
            currentIndex = (index + slides.length) % slides.length;
            carousel.setAttribute('data-current-slide', String(currentIndex));
            if (isVertical) {
                track.style.transform = `translateY(-${currentIndex * 100}%)`;
            }
            else {
                track.style.transform = `translateX(-${currentIndex * 100}%)`;
            }
            if (indicator) {
                indicator.textContent = `${currentIndex + 1} / ${slides.length}`;
            }
        };
        prevBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            updateCarousel(currentIndex - 1);
        });
        nextBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            updateCarousel(currentIndex + 1);
        });
        updateCarousel(currentIndex);
    });
}
