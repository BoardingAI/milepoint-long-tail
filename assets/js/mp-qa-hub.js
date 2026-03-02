document.addEventListener('DOMContentLoaded', function() {
    const detailsElements = document.querySelectorAll('.mp-facet-details');

    function updateDetailsState() {
        const isDesktop = window.innerWidth > 768;
        detailsElements.forEach(details => {
            if (isDesktop) {
                details.setAttribute('open', '');
            } else {
                details.removeAttribute('open');
            }
        });
    }

    // Run on initial load
    updateDetailsState();

    // Debounce the resize event for performance,
    // and only trigger on breakpoint state changes to prevent
    // resetting toggles during mobile scroll/nav bar shifts
    let resizeTimer;
    let lastIsDesktop = window.innerWidth > 768;

    window.addEventListener('resize', function() {
        const currentIsDesktop = window.innerWidth > 768;
        if (currentIsDesktop !== lastIsDesktop) {
            lastIsDesktop = currentIsDesktop;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(updateDetailsState, 150);
        }
    });
});
