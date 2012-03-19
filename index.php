<?php

$userList = array(
    // Define an array mapping IRC nicks (the "name" GET argument passed to this script) to
    //  temporary filenames in which to store the location data for the IRC module, e.g.:
    // 'MyNick' => '/tmp/irc-location_mynick',
);

class Coordinates {
    private $latRef;
    private $latDeg;
    private $latMin;
    private $latSec;
    private $lonRef;
    private $lonDeg;
    private $lonMin;
    private $lonSec;
    
    private $latDecimal;
    private $lonDecimal;
    
    public function __construct($latRef, $latDeg, $latMin, $latSec, $lonRef, $lonDeg, $lonMin, $lonSec, $latDecimal=null, $lonDecimal=null) {
        $this->latRef = $latRef;
        $this->latDeg = $latDeg;
        $this->latMin = $latMin;
        $this->latSec = $latSec;
        $this->lonRef = $lonRef;
        $this->lonDeg = $lonDeg;
        $this->lonMin = $lonMin;
        $this->lonSec = $lonSec;
        
        if (isset($latDecimal, $lonDecimal)) {
            $this->latDecimal = $latDecimal;
            $this->lonDecimal = $lonDecimal;
        } else {
            $this->calculateDecimalPair();
        }
    }
    
    private static function parseFraction($string) {
        return eval("return $string;");
    }
    
    public static function fromExifArrays($latRef, array $lat, $lonRef, array $lon) {
        return new Coordinates(
            $latRef, 
            self::parseFraction($lat[0]),
            self::parseFraction($lat[1]),
            self::parseFraction($lat[2]),
            $lonRef,
            self::parseFraction($lon[0]),
            self::parseFraction($lon[1]),
            self::parseFraction($lon[2])
        );
    }
    
    public static function fromDecimals($latitude, $longitude) {
        $latRef = ($latitude >= 0) ? 'N' : 'S';
        $lonRef = ($longitude >= 0) ? 'E' : 'W';
        
        $latDeg = floor(abs($latitude));
        $lonDeg = floor(abs($longitude));
        
        $latFrac = abs($latitude);
        $latFrac -= $latDeg;
        $lonFrac = abs($longitude);
        $lonFrac -= $lonDeg;
        
        $latMin = floor($latFrac * 60);
        $latSec = round((($latFrac * 60) - $latMin) * 60);
        $lonMin = floor($lonFrac * 60);
        $lonSec = round((($lonFrac * 60) - $lonMin) * 60);
        
        return new Coordinates($latRef, $latDeg, $latMin, $latSec, $lonRef, $lonDeg, $lonMin, $lonSec, $latitude, $longitude);
    }
    
    public function toPrettyCoordinates() {
        //  N50°12'3.45" W0°1'23.45"
        return "{$this->latRef}{$this->latDeg}°{$this->latMin}'{$this->latSec}\" {$this->lonRef}{$this->lonDeg}°{$this->lonMin}'{$this->lonSec}\"";
    }
    
    public function __toString() {
        return "{$this->queryLocationName()} ({$this->toPrettyCoordinates()} / {$this->toDecimalString()})";
    }
    
    private function calculateDecimalPair() {
        $lat = $lon = 0;
        
        $lat += $this->latDeg + ($this->latMin / 60) + ($this->latSec / 60 / 60);
        if ($this->latRef == 'S')
            $lat *= -1;
        
        $lon += $this->lonDeg + ($this->lonMin / 60) + ($this->lonSec / 60 / 60);
        if ($this->lonRef == 'W')
            $lon *= -1;
        
        $this->latDecimal = number_format(round($lat, 5), 5);
        $this->lonDecimal = number_format(round($lon, 5), 5);
    }
    
    public function toDecimalPair() {
        return array($this->latDecimal, $this->lonDecimal);
    }
    
    public function toDecimalString() {
        list($lat, $lon) = $this->toDecimalPair();
        return "$lat,$lon";
    }
    
    private $locationNameCache;
    public function queryLocationName($roundTo=4) {
        if (!isset($this->locationNameCache)) {
            $jsonString = file_get_contents("http://maps.googleapis.com/maps/api/geocode/json?latlng={$this->toDecimalString()}&sensor=false");
            $json = json_decode($jsonString);
            if ($json->status != 'OK') {
                $this->locationNameCache = "[LOCATION REQUEST FAILED]";
            } else {
                $this->locationNameCache = $json->results[0]->formatted_address;
            }
        }
        return $this->locationNameCache;
    }
}

if (isset($_POST['json'])) {
    if (isset($_GET['name'])) {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_GET['name']));
        if (isset($userList[$name])) {
            $json = json_decode($_POST['json']);
            $coords = Coordinates::fromDecimals($json->coords->latitude, $json->coords->longitude);
            $json->coords->geocode = $coords->queryLocationName(false);
            $json->coords->pretty = $coords->toPrettyCoordinates();
            
            $filename = $userList[$name];
            file_put_contents($filename, json_encode($json));
            chmod($filename, 0666);
        }
    }
    die();
}

?><!DOCTYPE HTML>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width initial-scale=1.0 maximum-scale=1.0 user-scalable=0">
    <title>Location IRCing!</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.2/jquery.min.js"></script>
</head>
<body>

<p id="loading">Fetching your location...</p>

<div id="loaded" style="display:none;">
    <p>Your location is:</p>
    <ul>
        <li>Latitude: <span id="lat"></span>°</li>
        <li>Longitude: <span id="lon"></span>°</li>
        <li>Lat/Lon Accuracy: <span id="latlonacc"></span> metres</li>
        <li>Altitude: <span id="alt"></span> metres above <a href="http://en.wikipedia.org/wiki/Reference_ellipsoid">reference ellipsoid</a></li>
        <li>Altitude Accuracy: <span id="altacc"></span> metres</li>
        <li>Heading: <span id="heading"></span>° from N</li>
        <li>Speed: <span id="speed"></span> ms<sup>-1</sup></li>
    </ul>
    
    <p>JSON-encoded raw data:</p>
    <pre id="json"></pre>
    
    <p id="posting">Posting your location back to PHP...</p>
</div>

<script type="text/javascript">
navigator.geolocation.getCurrentPosition(function(pos){
    $('#loading').hide();
    $('#lat').html(pos.coords.latitude);
    $('#lon').html(pos.coords.longitude);
    $('#latlonacc').html(pos.coords.accuracy);
    
    if (pos.coords.altitude !== null) {
        $('#alt').html(pos.coords.altitude);
    } else {
        $('#alt').closest('li').hide();
    }
    
    if (pos.coords.altitudeAccuracy !== null) {
        $('#altacc').html(pos.coords.altitudeAccuracy);
    } else {
        $('#altacc').closest('li').hide();
    }
    
    if (pos.coords.heading !== null) {
        $('#heading').html(pos.coords.heading);
    } else {
        $('#heading').closest('li').hide();
    }
    
    if (pos.coords.speed !== null) {
        $('#speed').html(pos.coords.speed);
    } else {
        $('#speed').closest('li').hide();
    }
    
    $('#loaded').show();
    
    var jsonString = JSON.stringify(pos);
    $('#json').html(jsonString);
    
    $.post('index.php<?= (isset($_GET['name']) ? '?name='.$_GET['name'] : '') ?>', {'json':jsonString}).done(function(data){
        $('#posting').html("Location saved!");
    }).fail(function(){
        $('#posting').html("Failed to save your location :(");
    });
}, function(err){
    switch (err.code) {
        case 1:
            $('#loading').html("You denied us access to your location! :( [" + err.message + "]");
            break;
        case 2:
            $('#loading').html("Your location is unavailable at this time. Does your device and browser support geolocation? [" + err.message + "]");
            break;
        case 3:
            $('#loading').html("Fetching your location is taking too long; our request timed out. [" + err.message + "]");
            break;
        case 0:
            $('#loading').html("Your location is unavailable for unknown reasons... [" + err.message + "]");
            break;
    }
}, {
    enableHighAccuracy : true,
//    timeout : 60000,
//    maximumAge : 30000
});
</script>

</body>
</html>