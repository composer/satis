import distanceInWordsToNow from 'date-fns/distance_in_words_to_now';

class DateDistance {
    static calculate(elements) {
        if (typeof elements === 'string') {
            elements = document.querySelectorAll(elements);
        }
        for (let i = 0; i < elements.length; i++) {
            let element  = elements[i];
            let datetime = element.attributes.datetime.value;
            let date     = new Date(datetime);
            let distance = distanceInWordsToNow(date, {
                addSuffix: true
            });
            
            element.textContent = distance;
        }
    }
}

export default DateDistance;
