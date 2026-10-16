# Seed: spec attributes per category

| code | en name | unit | type | filterable | categories |
|---|---|---|---|---|---|
| scan_engine | Scan engine | | select (1D laser, 1D CCD, 2D imager) | yes | scanners, TSD |
| interface | Interface | | multiselect (USB, RS232, Bluetooth, 2.4GHz, Ethernet, Wi-Fi) | yes | scanners, printers, RFID |
| ip_rating | IP rating | | select (IP42, IP52, IP54, IP65, IP67, IP68) | yes | scanners, TSD |
| drop_spec | Drop spec | m | number | no | scanners, TSD |
| print_method | Print method | | select (Direct thermal, Thermal transfer) | yes | label printers, labels |
| resolution | Resolution | dpi | select (203, 300, 600) | yes | label printers |
| print_width | Max print width | mm | number | yes | printers |
| print_speed | Print speed | mm/s | number | no | printers |
| paper_width | Paper width | mm | select (58, 80) | yes | receipt printers, receipt paper |
| auto_cutter | Auto-cutter | | boolean | yes | receipt printers |
| os | Operating system | | select (Android 11..14, Windows) | yes | TSD, POS terminals |
| screen_size | Screen size | inch | number | yes | TSD, POS terminals |
| battery | Battery | mAh | number | no | TSD, handheld RFID |
| rfid_frequency | RFID frequency | | select (UHF, HF, LF, NFC) | yes | RFID, TSD |
| read_range | Read range | m | number | no | RFID |
| antenna_ports | Antenna ports | | number | no | fixed RFID readers |
| label_width | Label width | mm | number | yes | labels |
| label_height | Label height | mm | number | yes | labels |
| core_diameter | Core diameter | mm | select (25, 40, 76) | yes | labels, ribbons |
| labels_per_roll | Labels per roll | pcs | number | no | labels |
| ribbon_type | Ribbon type | | select (Wax, Wax-resin, Resin) | yes | ribbons |
| ribbon_length | Ribbon length | m | number | yes | ribbons |
| ink_side | Ink side | | select (IN, OUT) | yes | ribbons |
| license_type | License type | | select (Perpetual, Subscription) | yes | software |
| license_term | License term | months | number | no | software |
| seats | Seats / devices | | number | no | software |
| service_duration | Duration | hours | number | no | services |
| sla_hours | Response SLA | hours | number | no | services |
