import { formatDistanceToNow } from 'date-fns'
import { enUS } from 'date-fns/locale'

class DateDistance {
  static calculate(elements) {
    if (typeof elements === 'string') {
      elements = document.querySelectorAll(elements)
    }

    for (let i = 0; i < elements.length; i++) {
      let element = elements[i]
      let datetime = element.attributes.datetime.value
      let date = new Date(datetime)
      let distance = formatDistanceToNow(date, {
        addSuffix: true,
        locale: enUS
      })

      element.textContent = distance
    }
  }
}

export default DateDistance
