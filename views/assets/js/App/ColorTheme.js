/**
 * Switches between light, dark and the system's preferred color theme
 * @class
 */
class ColorTheme {
    /**
     * Bootstraps the class
     * @param {string} themeRadios - Radio inputs to choose the color theme
     */
    constructor(themeRadios) {
        this.themeRadios = Array.prototype.slice.call(document.querySelectorAll(themeRadios))
        this.prefersDark = window.matchMedia('(prefers-color-scheme: dark)')
        this.init()
    }

    /**
     * Get the theme chosen by the user
     * @returns {string} One of auto, light or dark
     */
    getStoredTheme() {
        return window.localStorage.getItem('satisColorTheme') || 'auto'
    }

    /**
     * Applies a theme to the document, resolving auto to the system's preference
     * @param {string} theme - One of auto, light or dark
     */
    applyTheme(theme) {
        if ('auto' === theme) {
            theme = this.prefersDark.matches ? 'dark' : 'light'
        }
        document.documentElement.setAttribute('data-bs-theme', theme)
    }

    /**
     * Sets up event handlers and initial state
     */
    init() {
        const storedTheme = this.getStoredTheme()
        this.applyTheme(storedTheme)
        this.themeRadios.forEach((elem) => {
            elem.checked = storedTheme === elem.value
            elem.addEventListener('change', (event) => {
                window.localStorage.setItem('satisColorTheme', event.target.value)
                this.applyTheme(event.target.value)
            })
        })
        this.prefersDark.addEventListener('change', () => this.applyTheme(this.getStoredTheme()))
    }
}

export default ColorTheme
