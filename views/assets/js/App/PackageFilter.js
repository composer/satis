import $ from 'jquery'

class PackageFilter {
  constructor(input, list, listItem) {
    this.input = $(input)
    this.list = $(list)
    this.listItemSelector = listItem
    this.packages = this.list.find(listItem)
    this.inputTimeout = null
    this.readHash = this.readHash.bind(this)
    this.updateHash = this.updateHash.bind(this)
    this.filterPackages = this.filterPackages.bind(this)

    this.init()
  }

  readHash() {
    let hash = window.decodeURIComponent(window.location.hash.substr(1))

    if (hash.length > 0) {
      this.input.val(hash)
      this.filterPackages()
    }
  }

  updateHash() {
    window.location.hash = window.encodeURIComponent(this.input.val())
  }

  filterPackages() {
    let needle = this.input.val().toLowerCase()
    let closestSelector = this.listItemSelector

    this.list.hide()

    this.packages.each(function () {
      $(this).closest(closestSelector).toggle(
        $(this).text().toLowerCase().indexOf(needle) !== -1
      )
    })

    this.list.show()
  }

  init() {
    var instance = this

    instance.input.keyup(function () {
      instance.updateHash()
      window.clearTimeout(instance.inputTimeout)
      instance.inputTimeout = window.setTimeout(instance.filterPackages, 350)
    })

    $(window).keyup(function (event) {
      if (event.keyCode === 27) { // "ESC" keyCode
      instance.input.val('')
      instance.filterPackages()
    }
  })

  instance.readHash()
}
}

export default PackageFilter
