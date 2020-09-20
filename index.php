<?php

$stationID = $_GET["station"];
$conversion = $_COOKIE["conversion"];
$stationLat = NULL;
$stationLong = NULL;
$pm25 = NULL;
$humidity = NULL;
$stationName = "";
$url = 'https://www.purpleair.com/json?show=' . $stationID;

if ($stationID == NULL && !isset($_COOKIE["homeStation"])) {
    $loadSettings = TRUE;
} else {
    if ($stationID == NULL && isset($_COOKIE["homeStation"])) { 
        $stationID = $_COOKIE["homeStation"];
        $url = 'https://www.purpleair.com/json?show=' . $stationID;
    }
    
    for ($i = 0; $i < 2; $i++) { // try to fetch the data up to 2 times as endpoint is sometimes unreliable
        $r = json_decode(file_get_contents($url), true);
        if ($r != NULL) {
            //echo "<!-- \r\n"; var_dump($r);echo "\r\n -->"; //debug
            setValuesFromJSON($r, $pm25, $humidity);
            
            if ($conversion == "epa") { // adjustment based on EPA recommendations
                $pm25 = (0.52 * $pm25) - (0.085 * $humidity) + 5.71;
            } else if ($conversion == "larpa") { // larpa adjustment based on https://www.lrapa.org/DocumentCenter/View/4147/PurpleAir-Correction-Summary
                $pm25 = max(0, ($pm25 * 0.5) - 0.66); // no less than 0
            } else if ($conversion == "purpleair") { 
                // no adjustment
            } else {
                // unrecognized or unset, set to epa
                $conversion = "epa";
                //setcookie("conversion", $conversion, time() + (86400 * 30));
            }
            
            $aqi = aqiFromPM($pm25);
            
            $aqiDescription = getAQIDescription($aqi);
            setStationInfoFromJSON($r);
            break; 
        }
        sleep(2);
        $aqi = NULL;
    }
}

function setValuesFromJSON($r, &$pm25Value, &$humidityValue) {
    if ($r["results"] == NULL) { $pm25Value = $humidityValue = NULL; return NULL; }
    $pm = array();
    $humidity = array();
    foreach ($r["results"] as $sensor) {
        if($sensor["PM2_5Value"]) { $pm[] = $sensor["PM2_5Value"]; }
        if($sensor["humidity"]) { $humidity[] = $sensor["humidity"]; }
    }
    $pm25Value = array_sum($pm) / count($pm); 
    $humidityValue = array_sum($humidity) / count($humidity);
    return;
}
function setStationInfoFromJSON($r) {
    if ($r["results"] == NULL) {return NULL;}
    $GLOBALS['stationName'] = $r["results"][0]["Label"];
    $GLOBALS['stationLat'] = $r["results"][0]["Lat"];
    $GLOBALS['stationLong'] = $r["results"][0]["Lon"];
}
function getStationNameFromJSON($r) {
    if ($r["results"] == NULL) {return NULL;}
    return $r["results"][0]["Label"];
}
function aqiFromPM($pm) {
    if (is_nan($pm)) return "";
    if ($pm === NULL) return "";
    if ($pm < 0) return $pm;
    if ($pm > 1000) return "-";
    /*
        Good                            0 - 50        0.0 - 15.0     0.0 – 12.0
        Moderate                        51 - 100      >15.0 - 40     12.1 – 35.4
        Unhealthy for Sensitive Groups  101 – 150     >40 – 65       35.5 – 55.4
        Unhealthy                       151 – 200     >65 – 150      55.5 – 150.4
        Very Unhealthy                  201 – 300     >150 – 250     150.5 – 250.4
        Hazardous                       301 – 400     >250 – 350     250.5 – 350.4
        Hazardous                       401 – 500     >350 – 500     350.5 – 500
        */
    if ($pm > 350.5) {
      return calcAQI($pm, 500, 401, 500, 350.5);
    } else if ($pm > 250.5) {
      return calcAQI($pm, 400, 301, 350.4, 250.5);
    } else if ($pm > 150.5) {
      return calcAQI($pm, 300, 201, 250.4, 150.5);
    } else if ($pm > 55.5) {
      return calcAQI($pm, 200, 151, 150.4, 55.5);
    } else if ($pm > 35.5) {
      return calcAQI($pm, 150, 101, 55.4, 35.5);
    } else if ($pm > 12.1) {
      return calcAQI($pm, 100, 51, 35.4, 12.1);
    } else if ($pm >= 0) {
      return calcAQI($pm, 50, 0, 12, 0);
    } else {
      return undefined;
    }
}
function calcAQI($Cp, $Ih, $Il, $BPh, $BPl) {
    $a = ($Ih - $Il);
    $b = ($BPh - $BPl);
    $c = ($Cp - $BPl);
    return round(($a/$b) * $c + $Il);
}
function getAQIDescription($aqi) {
    if ($aqi === NULL) {
      return 'Oops..'; // error state  
    } else if ($aqi>= 401) {
      return 'Hazardous';
    } else if ($aqi>= 301) {
      return 'Hazardous';
    } else if ($aqi>= 201) {
      return 'Very Unhealthy';
    } else if ($aqi>= 151) {
      return 'Unhealthy';
    } else if ($aqi>= 101) {
      return 'Unhealthy for Sensitive Groups';
    } else if ($aqi>= 51) {
      return 'Moderate';
    } else if ($aqi>= 0) {
      return 'Good';
    } else if ($aqi == NULL) {
      
    } else {
      return undefined;  
    }
}
function getAQIMessage($aqi) {
    if ($aqi === NULL) {
      return 'Something went wrong. Please try again later.'; // error state  
    } else if ($aqi>= 401) {
      return '>401: Health alert: everyone may experience more serious health effects';
    } else if ($aqi>= 301) {
      return '301-400: Health alert: everyone may experience more serious health effects';
    } else if ($aqi>= 201) {
      return '201-300: Health warnings of emergency conditions. The entire population is more likely to be affected. ';
    } else if ($aqi>= 151) {
      return '151-200: Everyone may begin to experience health effects; members of sensitive groups may experience more serious health effects.';
    } else if ($aqi>= 101) {
      return '101-150: Members of sensitive groups may experience health effects. The general public is not likely to be affected.';
    } else if ($aqi>= 51) {
      return '51-100: Air quality is acceptable; however, for some pollutants there may be a moderate health concern for a very small number of people who are unusually sensitive to air pollution.';
    } else if ($aqi>= 0) {
      return '0-50: Air quality is considered satisfactory, and air pollution poses little or no risk';
    } else {
      return undefined;
    }
}
function getAQIColors($aqi) {
    if ($aqi === NULL) { return "color:black; background-color:#F0F0F0"; // error state
    } else if ($aqi < 15) { return "color:black; background-color:rgba(107, 226, 67, 1)";
    } else if ($aqi < 40) { return "color:black; background-color:rgba(219, 248, 81, 1)";
    } else if ($aqi < 50) { return "color:black; background-color:rgba(255, 255, 85, 1)";
    } else if ($aqi < 70) { return "color:black; background-color:rgba(249, 206, 71, 1)";
    } else if ($aqi < 90) { return "color:black; background-color:rgba(243, 165, 60, 1)";
    } else if ($aqi < 110) { return "color:white; background-color:rgba(238, 115, 48, 1)";
    } else if ($aqi < 170) { return "color:white; background-color:rgba(200, 42, 50, 1)";
    } else if ($aqi < 200) { return "color:white; background-color:rgba(140, 26, 75, 1)";
    } else if ($aqi < 300) { return "color:white; background-color:rgba(134, 25, 66, 1)";
    } else if ($aqi < 400) { return "color:white; background-color:rgba(115, 20, 37, 1)";
    } else { return "color:white; background-color:rgba(115, 20, 37, 1)";}
}
function renderStationToolbar() {
    echo '<a href="https://www.purpleair.com/map?select=' . $GLOBALS['stationID'] .
            '#13.0/'. $GLOBALS['stationLat'] . '/' . $GLOBALS['stationLong'] .'" target="_blank">';
    if (isset($_COOKIE["homeStation"]) && $_COOKIE["homeStation"] == $GLOBALS["stationID"]) {
        echo 'Home Station: ' . $GLOBALS['stationName'] . '</a>  | <a href="#" onclick="changeStation();">Change Station</a> ';
    } else {
        echo 'Station: ' . $GLOBALS['stationName'] . '</a> ';
        echo '| <a href="#" onclick="changeStation();">Change Station</a> '; 
        echo '<br> <a href="" onclick="setHome();">Set as Home</a> | <a href=".">Go to Home Station</a>';
    }

}
function renderConversionsToobar() {
    echo '<div id="conversions" style="margin-top:5px;">
            <a id="currentConversion" href="#" onclick="changeConversion();">';
    if ($GLOBALS["conversion"] == "purpleair") { echo 'Purpleair Standard AQI'; }
    else if ($GLOBALS["conversion"] == "larpa") { echo 'AQI Conversion: LARPA'; }
    else if ($GLOBALS["conversion"] == "epa") { echo 'AQI Conversion: EPA'; }
    echo '  </a>
            <select id="select_conversions" style="display:none;" onchange="setConversion();">
                <option value="" selected>-- Choose a Conversion --</option>
                <option value="purpleair">Purple Air Standard</option>
                <option value="epa">EPA</option>
                <option value="larpa">LARPA</option>
            </select>
         </div>';
}
?>
<html>
    <head>
        <meta http-equiv="refresh" content="600">
        <meta name="viewport" content="width=device-width, user-scalable=yes" />
        <link rel="stylesheet" href="aqi.css">        
        <title>Simple AQI</title>
        <link rel="icon" sizes="192x192" href="icon.png">
        <link rel="apple-touch-icon" href="icon.png">
        <meta name="apple-mobile-web-app-title" content="AQI">
        <script>
            function refreshOnFocus() {
                window.addEventListener("focus", refresh, false);
            }
            function removeRefreshOnFocus() {
                window.removeEventListener("focus", refresh, false);
            }
            function refresh() {
                location.reload(true);
            }
            function changeStation() {
                removeRefreshOnFocus();
                document.getElementById("reading").style.display = "none";
                document.getElementById("toolbar").style.display = "none";
                document.getElementById("change_station").style.display = "inline";
                document.body.style = "background-color:#F0F0F0;"
            }
            function setStation() {
                var x = document.forms["station"]["purpleairURL"].value;
                if (x == "") {
                    shakeElementError("copy_paste");
                    return false;
                } else {
                    var exp = new RegExp("select=[0-9]*");
                    var selection = exp.exec(x);
                    var result = selection[0].split("=")[1];
                    window.location.href = "?station=" + result;
                    return false;
                }
            }
            function shakeElementError(id) {
                e = document.getElementById(id);
                e.style = "color:red;";
                e.classList.add("shake-constant");
                setTimeout(function(){ 
                    e.classList.remove("shake-constant");
                    e.style = "color:inherit;"; 
                }, 3000);
                
            }
            function setHome() {
                deleteCookie("homeStation");
                setCookie("homeStation", "<?php echo $stationID; ?>", 365);
                setTimeout(function(){ 
                    //location.replace(location.href.substring(0, location.href.indexOf('?')));
                    location.reload(true);
                }, 1000);
            }
            function changeConversion() {
                document.getElementById("currentConversion").style="display:none;";
                document.getElementById("select_conversions").style="display:block;";
            }
            function setConversion() {
                var value = document.getElementById("select_conversions").value;
                if (value == "") {
                    return; 
                } else {
                    deleteCookie("conversion");
                    setCookie("conversion", value, 365);
                    location.reload(true);
                }
            }
            function setCookie(cname, cvalue, exdays) {
                var d = new Date();
                d.setTime(d.getTime() + (exdays*24*60*60*1000));
                var expires = "expires="+ d.toUTCString();
                document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
            }
            function deleteCookie(cname, path, domain) {
                if( getCookie(name) ) {
                document.cookie = name + "=" +
                  ((path) ? ";path="+path:"")+
                  ((domain)?";domain="+domain:"") +
                  ";expires=Thu, 01 Jan 1970 00:00:01 GMT";
                }
            }
            function getCookie(name){
                return document.cookie.split(';').some(c => {
                    return c.trim().startsWith(name + '=');
                });
            }
        </script>
    </head>
    <body style="<?php echo getAQIColors($aqi); ?>" <?php if (!$loadSettings) echo "onload=\"refreshOnFocus();\""; ?>>
        <div id="main" class="main">
            <div id = "reading" <?php if ($loadSettings) echo "style=\"display:none\""; ?>>
                <div id="aqi" class="aqi"><?php echo $aqi; ?></div>
                <div id="aqi_desc" class="description<?php if (strlen($aqiDescription) > 15) echo "-long"; ?>"><?php echo $aqiDescription; ?></div>
                <div id="aqi_msg" class="message"><?php echo getAQIMessage($aqi); ?></div>
            </div>
            <div id="toolbar" class="toolbar" <?php if ($loadSettings) echo "style=\"display:none\""; ?>> 
                <?php renderStationToolbar(); ?>
                <?php renderConversionsToobar(); ?>
            </div>
            <div id="change_station" class="change_location" <?php if ($loadSettings) { echo "style=\"display:block\"";} else {echo "style=\"display:none\""; } ?>>
                <div class = "description-long">Select a Purpleair Station</div>
                <div class = "instructions" style="text-align:left;">
                    <div>1. Go to the <a href="https://www.purpleair.com/map" target="_blank" style="text-decoration:underline;">Purpleair map</a>.</div>
                    <div>2. Find a station and click on it.</div>
                    <div id="copy_paste" class="shake-horizontal">3. Copy and paste the URL below.</div>
                    <form name="station" action="" onsubmit="setStation(); return false;">
                        <input type="text" id="purpleairURL" name="purpleairURL" style="min-width: 300px; min-height: 20px; padding: 10px;" placeholder="e.g. https://www.purpleair.com/map?opt=1/mAQI/a10/cC0&select=64839#13.3/37.79089/-122.44498">
                        <input type="submit" value="Go" style="min-height: 30px; padding: 10px;">
                    </form>
                </div>
            </div>
        </div>
    </body>
</html>

