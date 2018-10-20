import $ from 'jquery';
import distanceInWordsToNow from 'date-fns/distance_in_words_to_now' 

class DateDistance {
    static calculate($elements) {
        $($elements).each(function() {
            let element  = $(this);
            let datetime = element.attr('datetime');
            let date     = new Date(datetime);
            
            element.text(`${distanceInWordsToNow(date)} ago`);
        });
    }
}

export default DateDistance;
