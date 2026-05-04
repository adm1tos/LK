// кнопка сворачивания шторки

document.addEventListener('DOMContentLoaded', () => {
    const toggleButton = document.getElementById('sidebarToggle');

    if (!toggleButton) {
        return;
    }

    const savedState = localStorage.getItem('sidebarCollapsed');

    if (savedState === '1') {
        document.body.classList.add('sidebar-collapsed');
        toggleButton.setAttribute('aria-expanded', 'false');
    }

    toggleButton.addEventListener('click', () => {
        const isCollapsed = document.body.classList.toggle('sidebar-collapsed');

        localStorage.setItem('sidebarCollapsed', isCollapsed ? '1' : '0');
        toggleButton.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    });
});