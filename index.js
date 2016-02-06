var Crawler = require("crawler");
var url = require('url');
var lastCourse = null;
var str_replace = require('str_replace');


var c = new Crawler({
    maxConnections : 10,
    // This will be called for each crawled page
    callback : function (error, result, $) {
        console.log('CROWL:' + result.request.uri.href);
        var course = { date: result.options.date };

        var $courseInfos = $('#detailCourseInfos');
        var tmpRC = $courseInfos.find('#detailCourseLiveRC').text().match(/R([0-9]+)C([0-9]+)/);
        course.reunion = parseInt(tmpRC[1]);
        course.course = parseInt(tmpRC[2]);
        course.time = $courseInfos.find('#detailCourseLiveHeure').text().replace('h', ':');
        var $hipodromeInfo = $courseInfos.find('#detailCourseLiveHippodrome');
        course.hippodrome = {
            name: $hipodromeInfo.text(),
            turfomaniaId: $hipodromeInfo.find('a').attr('href').match(/idhippo=([0-9]+)/i)[1]
        };
        course.name = $courseInfos.find('#detailCourseAutresCourse').text().match('-\\s(.+)')[1];
        var detailCourseCaract = $('.detailCourseCaract p').first().text();
        var detailCourseType = detailCourseCaract.match(/(Plat|Steeple|Haies|Attelé|Monté)/i);
        course.type = detailCourseType ? detailCourseType[1].toLowerCase().substr(0, 1) : 'p';
        var detailCourseDistance = detailCourseCaract.match(/([0-9]+[\.,]{0,1}[0-9]+)\s{0,1}m/i);
        course.distance = detailCourseDistance ? parseInt(detailCourseDistance[1].replace('.', '')) : null;

        course.resultat = [];

        //arrivé
        $('#colTwo > table.tableauLine tbody tr').each(function(){
            var partant = {};
            $(this).find('td').each(function(index){
                switch (index) {
                    case 0: //place
                        partant.place = $(this).text() == '-' ? '>9' : $(this).text();
                        break;
                    case 1: //numero
                        partant.numero = parseInt($(this).text());
                        break;
                    case 2: //cheval
                        partant.cheval = {
                            turfomaniaId: $(this).find('a').attr('href').match(/_([0-9]+)$/i)[1],
                            name: $(this).text()
                        };
                        break;
                    case 3: //driver
                        partant.driver = {
                            turfomaniaId: $(this).find('a').attr('href').match(/idjockey=([0-9]+)$/)[1],
                            name: $(this).text()
                        };
                        break;
                    case 4: //entraineur
                        partant.entraineur = {
                            turfomaniaId: $(this).find('a').attr('href').match(/identraineur=([0-9]+)$/)[1],
                            name: $(this).text()
                        };
                        break;
                    case 6: //cote
                        var cote = parseFloat($(this).text());
                        partant.cote = cote ? cote : null;
                        break;
                }
            });
            course.resultat.push(partant);
        });

        /*
        course.type = detailCourseCaract[2];
        course.categorie = detailCourseCaract[3];
        course.distance = detailCourseCaract[4];*/

        console.log(course);

        console.log('');

        /*var header = $('#page-title .h4').first();
        var meteo = 0;
        try {
            if (header.find('div img')) {
                meteo = header.find('div img').attr('src').match(new RegExp('/([0-9]+)\.png'))[1];
            }
        } catch(e) {
            //ne rien faire
        }

        //var date = $('#page-title h4.mar-no').first().;
        //console.log(date);

        var regexHeader = new RegExp('---(-{0,1}[0-9]{1,2}).([A-Z])\\s-\\s(-{0,1}[0-9]{1,2}).([A-Z])---(.+)---([0-9]{1,2}:[0-9]{1,2})\\s-\\sRéunion\\s([0-9]{1,2})\\sCourse\\s([0-9]{1,2})\\s-\\s(.+)---(.+)\\s-\\s([0-9]+)([A-Za-z]{0,3})\\s-\\s([0-9.]+)(.)---');
        var headerText = str_replace('<br>', '---', header.html());
        headerText = $(str_replace('</div>', '---', headerText)).text();
        console.log(headerText);
        var headerData = headerText.match(regexHeader);

        if (headerData[2] != 'C' || headerData[4] != 'C') {
            throw new Error('Temp not in °C')
        }

        if (headerData[12] != 'm') {
            throw new Error('Not distance in metre')
        }

        if (headerData[14] != '€') {
            throw new Error('Not cashprice in €')
        }


        var href = result.request.uri.href;
        var canalturfId = href.match(new RegExp('/([0-9]+)_'))[1];
        var date = href.match(new RegExp('/([0-9]{4})-([0-9]{2})-([0-9]{2})/'));
        date = {year: date[1],mouth: date[2], day: date[3]};

        var infoCourse = {
            canalturfId: canalturfId,
            date: date,
            meteo: meteo,
            tempMin: headerData[1],
            tempMex: headerData[3],
            name: headerData[5],
            time: headerData[6],
            reunionNum: headerData[7],
            courseNum: headerData[8],
            hypodrome: headerData[9],
            type: headerData[10],
            distance: headerData[11],
            cashprice:  headerData[13]

        };


        console.log(infoCourse);*/


    },
    onDrain: function(){
        process.exit(1);
    }
});


var date = new Date();


for(var i = 1; i <= 1; i++) {
    date.setDate(date.getDate()-i);
    var day = ("0" + date.getDate()).slice(-2);
    var mouth = ("0" + (date.getMonth() + 1)).slice(-2);
    var url = 'http://www.turfomania.fr/arrivees-rapports/index.php?choixdate=' + day + '/' + mouth + '/' + date.getFullYear();


    c.queue([{
        uri: url,

        // The global callback won't be called
        callback: function (error, result, $) {
            console.log('CROWL:' + result.request.uri.href);
            $('#colTwo .trOne a.btn').each(function(){
                var href = $(this).attr('href').replace('../', 'http://www.turfomania.fr/').replace('partants-', 'rapports-');
                c.queue([{url: href, date: date.getFullYear() + '-' + mouth + '-' + day}]);
            });
        }
    }]);
}

/*c.queue([{
    uri: url,

    // The global callback won't be called
    callback: function (error, result, $) {
        console.log($('#page-title .text-xs').text());
        var href = $('#acc-listecourses .panel-primary:last-child a.list-group-item:last-child').attr('href');
        lastCourse = href.match(new RegExp('/([0-9]+)_'))[1];

        console.log(lastCourse);
        for (var i = 130920; i <= lastCourse; i++) {
            var newHref = href.replace(lastCourse, i);
            console.log('CROWL:' + newHref);
            c.queue(href.replace(lastCourse, i));
        }
    }
}]);*/