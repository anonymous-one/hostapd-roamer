#!/bin/sh

if [ $2 = "BEACON-RESP-RX" ]; then
        echo "$6" > "/tmp/beaconresp.$3"
fi
