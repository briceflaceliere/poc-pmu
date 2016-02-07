var Crawler = require("crawler");
var url = require('url');
var lastCourse = null;
var str_replace = require('str_replace');

var mysql      = require('mysql');
var connection = mysql.createConnection({
    host     : process.env.MYSQL_PORT_3306_TCP_ADDR,
    user     : process.env.MYSQL_ENV_MYSQL_USER,
    password : process.env.MYSQL_ENV_MYSQL_PASSWORD,
    database : process.env.MYSQL_ENV_MYSQL_DATABASE
});

connection.connect();

connection.query('SELECT 1 + 1 AS solution', function(err, rows, fields) {
    if (err) throw err;

    console.log('The solution is: ', rows[0].solution);
});


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


    },
    onDrain: function(){
        connection.commit(function(err) {
            if (err) {
                return connection.rollback(function() {
                    throw err;
                });
            }
            console.log('success!');
        });

        connection.end();
        process.exit(0);
    }
});


var date = new Date();

connection.beginTransaction(function(err) {
    if (err) { throw err; }
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

});