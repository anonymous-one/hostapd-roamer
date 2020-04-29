<?php

// all mac addresses must be lower case

$data=array();
$data['adapters']['2']=array('wlan0','aa:aa:aa:aa:aa:aa'); // your 2ghz adapter, remove if you do not have one
$data['adapters']['5']=array('wlan1','bb:bb:bb:bb:bb:bb'); // your 5ghz adapter, remove if you do not have one
$data['bssid']='My_SSID'; // your accesspoint ssid (all access points must have the same ssid)
$data['lastneighupdate']=0;
$data['servertype']='hostapd'; // hostapd or openwrt
$data['rssi-2-to-5']=60; // try to roam to own 5ghz at this 2ghz rssi
$data['rssi-2-to-5-min']=65; // if 5ghz has this rssi, roam
$data['rssi-5-to-other']=65; // scan $beaconcheck[5] as this rssi
$data['rssi-5-to-other-min']=62; // if candidate has this rssi, roam
$data['rssi-2-to-other']=70; // scan $beaconcheck[2] as this rssi
$data['rssi-2-to-other-min']=65; // if candidate has this rssi, roam

$roamers=array();
$roamers['cc:cc:cc:cc:cc:cc']=array('failedbeacons'=>0); // mac address of your client

$neigh=array();
$neigh['dd:dd:dd:dd:dd:dd']='....'; // mac + neighbor report for everyone of your access points (2+5ghz, all of them. i repeat, all of them!)

$beaconcheck=array(); // all the entries below MUST be listed in $neigh
$beaconcheck[2][]='ee:ee:ee:ee:ee:ee'; // list of possible roam to candidates for rssi-2-to-other + rssi-2-to-other-min (can be 2 or 5ghz, must be in $neigh)
$beaconcheck[2][]='ff:ff:ff:ff:ff:ff';
$beaconcheck[5][]='ee:ee:ee:ee:ee:ee'; // list of possible roam to candidates for rssi-5-to-other + rssi-5-to-other-min (can be 2 or 5ghz, must be in $neigh)
$beaconcheck[5][]='ff:ff:ff:ff:ff:ff';

?>
