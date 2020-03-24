<?php

/*
general note : all mac addresses must be in lower case (eg: aa:bb:cc not AA:BB:CC...)
*/

$data=array();

/*
adapters have the following syntax:

$data['adapters']['frequency = 2 or 5']=array('wlanX device (wlan0 or wlan1 in most cases)','mac address');

example : 
*/
$data['adapters']['2']=array('wlan0','a1:a1:a1:a1:a1:a1');
$data['adapters']['5']=array('wlan1','a2:a2:a2:a2:a2:a2');

// the bssid of your accesspoints
$data['bssid']='MyHomeWireless';

// do not change this, view rssi.php to see what its used for. just somewhere to store when the neighbor report entries have last been stored.
$data['lastneighupdate']=0;

// the type of device this is running on. valid values are hostapd OR openwrt. hostapd is used for a 'normal' linux install where ubus is not available.
$data['servertype']='hostapd';

// how strong should the signal at 2.4ghz be before we try to force a roam to 5ghz on the same access point, i use 60.
$data['rssi-2-to-5']=60;

// how weak should the signal be at 5ghz before we try to force a roam to an access point listed in $beaconcheck[5] (below)
$data['rssi-5-to-other']=68;

// when rssi-5-to-other condition is met, how strong should be signal to the access points we are scanning be before we actually do a roam-to. i use 65.
$data['rssi-5-to-other-min']=65;

/*
the roamers (clients). these are devices you want to control the roaming of.

format is:
$roamers['Mac address of client device']=array();
*/
$roamers=array();
$roamers['c1:c1:c1:c1:c1:c1']=array();

/*
the neighbor list table.

format is:
$neigh['a1:a1:a1:a1:a1:a1']='Neighbor report entry';

put in each of your access points, their mac address and their corresponding neighbor report.

to get 'your own' neighbor report entry via hostapd (this is why hostapd-ct is so helpful):
hostapd_cli -i (wlan0 or wlan1, depending on which adapter you are fetching from) show_neighbor

to get 'your own' neighbor report entry via OpenWRT:
ubus call hostapd.wlan0 rrm_nr_get_own (once again, wlan0 / wlan1 depending on adapter)

populate this fully, and correctly. every access point should have all the possible access points you will be roaming to, every cross band (2->5 and 5->2).
*/
$neigh=array();
$neigh['a1:a1:a1:a1:a1:a1']='my-neighbor-report-for-a1@2ghz...';
$neigh['a2:a2:a2:a2:a2:a2']='my-neighbor-report-for-a1@5ghz...';

/*
this is a list of possible access points we will roam to.

[2] = when signal low at 2.4ghz, try to roam to these mac addresses.
[5] = when signal low at 5ghz, try to roam to these mac addresses.

these have to match the neighbor report list table above. you of course do not need to use every one listed in the neighbor list table, but whatever is here, must be in the neighbor report table as the script will convert mac -> channel + frequency using the neighbor report table.
*/
$beaconcheck=array();
# weak 2.4ghz can roam to
$beaconcheck[2][]='a1:a1:a1:a1:a1:a1';
# weak 5ghz can roam to
$beaconcheck[5][]='a2:a2:a2:a2:a2:a2';

$beaconcache=array();

?>
