**Hostapd-roamer**

This is a simple PHP based script that will forcefully roam a client device to multiple access points using plain hostapd or OpenWRT's ubus to hostapd interface.

It's not super polished, but works quite well for me. Don't expect to have it running perfectly without making some changes. I am posting this strictly because I think someone out there will find it useful.

It is a bit tailored to my home, which has a dual band access point on each floor. This means that I have a strong 5ghz signal everywhere. So it is setup to try and get the client on to a 5ghz access point as quickly as possible. More details how this is done below.

**Requirements**

There are quite a few:

**A) OpenWRT**

OpenWRT 19 or higher. This script makes use of a few of the fairly recently added ubus calls which I believe were added somewhere between 18 or 19.

Packages hostapd-utils coreutils-timeout php7-cli.

You need to have 802.11r, 802.11k and 802.11v configured and running in the /etc/config/wireless configuration file.

Here is a quick dump of the various settings that are applicable, some (in perticular nassid and r1_key_holder) will need to be changed in your individual case:

```
option mobility_domain 'e612'
option ieee80211r '1'
option ft_psk_generate_local '1'
option pmk_r1_push '1'
option nasid 'CHANGE ME'
option r1_key_holder 'CHANGE ME'
option ft_over_ds '1'
option ieee80211k '1'
option ieee80211v '1'
option bss_transition '1'
```

This is by far not a guide on how to setup 802.11r/k/v on OpenWRT, but there is plenty of info out there on how to get it configured.

**B) Standalone hostapd (in my case Debian Stretch)**

You will want to get hostapd-ct (www.candelatech.com version @ https://github.com/greearb/hostap-ct/tree/master/hostapd) installed. Although possibly not totally required, the main purpose of having this installed is to get the ```hostapd_cli show_neighbor``` command. It's quite possible this will eventually make its way into hostapd (non ct) as its quite useful, but as of March 2020 it has not. This command will dump the currently stored neighbor table, which is useful for getting your own neighbor report entry. There are other ways of getting your own neighbor report entry which I won't cover here. But for what its worth, you can use wpa_cli on the client end to fetch a list of neighbor entries from the currently connected to access point and get it that way. Last but not least the script uses the ```hostapd_cli show_neighbor``` command to check what entries are already in the neighbor table instead of overwriting them every 2 minutes. Overwriting them should be fine, so you can most likely skip hostapd-ct if you are able to get 'your own' neighbor report out of hostapd somehow.

You will again need to have 802.11r/k/v running on hostapd. I won't get into how to set this up on a raw hostapd instance as there is plenty of info out there.

**C) Common (OpenWRT + Hostapd)**

Your access points must be using the same BSSID. And as mentioned above 802.11r/k/v must be enabled and running.

Although not required, I highly recommend every access point uses the same authentication as well. And in the 5ghz band, the same channel. My setup looks like this: All APs the same BSSID + Authentication (WPA2), on the 5ghz band all APs the same channel and on the 2.4ghz band since I have 3 access points channels 1 6 and 11.

Not 100% necessary but I have noticed this works quite a bit better with Qualcomm based adapters. Mediatek adapters don't quite bridge the wireless interface correctly and broadcast packets are lost on inital connection. That was at least the case for me. I am running ath9k / ath10k adapters on all floors after replacing the MediaTek based RE650 on my middle floor.

**D) Client Device**

You will need a client device that supports 802.11v. I am currently using a Samsung S7 Edge (old by todays standards) running Android 8.0 and it supports it. You can be fairly certain anything made post 2017 _should_ support 802.11v.

Ideally your client device will have a static IP assigned to it. The reason for this is because once the RSSI as reported by ```iw``` gets stale (over a second), the script will send an ICMP echo (ping) to the client device. This forces the client to send out a packet and the RSSI in iw gets updated. A quick note on this, no this will not keep sending pings even when the screen is off. What tends to happen on my S7 Edge is after XX seconds (30-60) the device will not answer to pings if the screen is off. The script is set up in a way that once one ping is sent out, unless the inactivity time of the device drops back below 1 second (so some activity has taken place), it will not send another one. This is how I keep the ```iw``` RSSI fresh.

**How it works - Basic Idea**

The script has 3 triggers for when a roam is forced:

**a) Client connected at 2.4ghz, signal strength high** = Try to roam to the same access point, but 5ghz

**b) Client connected at 5ghz, signal strength medium / low** = Try to roam to another access point (you can specify 2.4ghz or 5ghz as it uses a neighbor report entry, so its agnostic to band)

**c) Client connected at 2.4ghz, signal strength low** = Try to roam to another access point (you can specify 2.4ghz or 5ghz as it uses a neighbor report entry, so its agnostic to band)

So in my home, since I have a dual band access point on each floor, as quickly as possible I try to force a roam to 5ghz on the same floor and then wait for 2 b or c to trigger if / when needed. This works well in my case as even at 5ghz, there is a bit of overlap where one access point's signal gets weak and the next one's gets strong. So most of the time I am able to roam between 5ghz bands without touching the 2.4ghz band.

**How it works - 802.11v**

Just a quick overview on how 802.11v (as I am using it) works. I am going to butcher the terminology here as wifi internals are not my specialty but here is how I understand it to work.

The script uses beacon requests to ask the client device to report the beacons it is picking up. So you tell the client "hey, listen for a beacon from XX:XX:XX:XX:XX:XX and send me back what you hear". This info is then sent back by the client to the currently connected to access point. Among the various information that is sent back is the RSSI of how strong the beacon was. This is how we check where we can possible roam to.

We then use BSS-TM to force the client to switch access points. BSS-TM is essentially a request sent to the client device saying "hey, I would like you to roam to this access point". The script lets the client know that this is a prefered access point as well as that its bridged to the same network. These 2 additional details (according to Samsungs Roaming FAQ) are kind of +1's on the roaming decision. The client can still decide not to roam, but in my case with my S7 Edge, it never says no. Even if I try to roam to an access point that has a very weak signal (80 for example), it will still connect. Only to roam back to the strongest one on its own XX seconds later.

That's all there is to it.

**Parts of the script**

The script has 3 parts:

**a) wifievent.sh** = A script that is executed via hostapd_cli when a beacon request response comes back from the device so it can be saved and passed back to rssi.php.

**b) rssi.config.php** = A basic configuration file with various settings

**c) rssi.php** = The infinite loop script that does 2 things: a) Forces the device to roam b) Sends out and waits for a response for beacon requests from client devices.

**Configuration - hostapd_cli + wifievent.sh**

Place wifievent.sh wherever you like, and add the following to your rc.local file:

```
(while true; do sleep 10 ; /usr/sbin/hostapd_cli -a /LOCATION/wifievent.sh -i ADAPTER 2>&1 >> /var/log/hostapd.log; done) &
```

Location = Where wifievent.sh is located.
Adapter = The adapter to listen to events on. So if you have 2 adapters (most commonly wlan0 / wlan1) you will need two of the above. Here is exactly what is in my rc.local files on both my Debian and OpenWRT access points:

```
(while true; do sleep 10 ; /usr/sbin/hostapd_cli -a /etc/scripts/wifievent.sh -i wlan0 2>&1 >> /var/log/hostapd.log; done) &
(while true; do sleep 10 ; /usr/sbin/hostapd_cli -a /etc/scripts/wifievent.sh -i wlan1 2>&1 >> /var/log/hostapd.log; done) &
```

Once you have placed the script wherever you so desire and added the above to your rc.local, reboot your device wait a few seconds and check that hostapd_cli has launched. Doing a ```ps | grep hostapd_cli``` (in OpenWRT) or ```ps aux | grep hostapd_cli``` (in Debian / normal linux) should give you somthing like:

```
root      1987  0.0  0.0   8480  1652 ?        S    Mar19   0:07 /usr/sbin/hostapd_cli -a /etc/scripts/wifievent.sh -i wlan0
root      1988  0.0  0.0   8480  1636 ?        S    Mar19   2:32 /usr/sbin/hostapd_cli -a /etc/scripts/wifievent.sh -i wlan1
```

This means that hostapd_cli is up and running, listening for events on wlan0 and wlan1, and will execute /etc/scripts/wifievent.sh when an event has taken place.

**Configuration - rssi.config.php**

Information about the various settings the script takes is found inside the file. I have commented it fairly quite a bit so everything you need is in there. Only note is that it must be in the same folder as rssi.php and also, **all mac addresses must be in lower case**.

**Configuration - rssi.php**

Once the 2 above are done, you are ready to start running the rssi.php script.

Initally, you may want to just run it via your console using:

```
php-cli /LOCATION/rssi.php
```

This will give you a live view of the logging the script dumps.

Once you decide you want to have this running at all times, add to rc.local via:

```
(while true; do sleep 60 ; /usr/bin/php-cli /etc/scripts/rssi.php >> /var/log/hostapd.log ; done) &
```

**Logging**

You should get some basic logged output to /var/log/hostapd.log.

**Final notes**

Remember, you are going to need to have **a copy of this running on each access point you want to roam from**. And of course the rssi.config.php file will need to be specific to that access point. In my case, 3 routers, each running a copy of this script. Currently its **setup to support a single roaming device (client)**. I put the foundation in place to support multiple clients, but in my home, its really only my cellphone I care about.

And finally, this is a bit of a custom script tailored to my use case so YMMV. Enjoy!
