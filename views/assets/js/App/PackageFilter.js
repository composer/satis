class PackageFilter {
  constructor(input, list, listItem) {
    this.input = document.querySelector(input)
    this.list = document.querySelector(list)
    this.packages = Array.prototype.slice.call(this.list.querySelectorAll(listItem))
    this.inputTimeout = null
    this.readHash = this.readHash.bind(this)
    this.updateHash = this.updateHash.bind(this)
    this.filterPackages = this.filterPackages.bind(this)

    this.init()
  }

  readHash() {
    let hash = window.decodeURIComponent(window.location.hash.substr(1))

    if (hash.length > 0) {
      this.input.value = hash
      this.filterPackages()
    }
  }

  updateHash() {
    window.location.hash = window.encodeURIComponent(this.input.value)
  }

  filterPackages() {
    let needle = this.input.value.toLowerCase()

    this.list.style.display = "none"

    this.packages.forEach(function (elem) {
      let displayPackage = elem.textContent.toLowerCase().indexOf(needle) !== -1
      elem.style.display = displayPackage ? "block" : "none"
    })

    this.list.style.display = "block"
  }

  init() {
    var instance = this

    instance.input.addEventListener("keyup", function () {
      instance.updateHash()
      window.clearTimeout(instance.inputTimeout)
      instance.inputTimeout = window.setTimeout(instance.filterPackages, 350)
    })

    document.addEventListener("keyup", function (event) {
      if (event.code === 27) { // "ESC" keyCode
      instance.input.value = ""
      instance.filterPackages()
    }
  })

  instance.readHash()
}
}

export default PackageFilter
