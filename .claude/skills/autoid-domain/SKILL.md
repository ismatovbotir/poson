---
name: autoid-domain
description: Domain knowledge for the Auto-ID business. Covers barcode scanners, label/receipt printers, mobile computers (TSD), RFID, POS equipment, consumables (labels, ribbons, paper), software licenses and device services. Use when modelling catalog data, naming things, choosing spec attributes, or writing product content.
---

# Auto-ID domain

The company **sells** Auto-ID hardware, consumables and software. It also **services** devices: repair, setup, integration and support contracts.
Customers are mostly B2B: retail, warehouses, logistics, pharmacies, HoReCa and manufacturing.

## Product types (`App\Enums\ProductType`)

| Type | Examples | Sales flow | Notes |
|---|---|---|---|
| `hardware` | Scanners, printers, TSD, RFID readers, POS terminals | Cart **or** quote | Has brand, model, SKU, warranty months, spec attributes |
| `consumable` | Labels, ribbons, receipt paper, RFID tags | Cart | Sold by unit, roll, box or pack. Has a minimum order qty. Sizes matter |
| `software` | Inventory apps, POS software, label design, MDM licenses | Cart **or** quote | License type (perpetual or subscription), term, seats |
| `service` | Setup, integration, repair, support contract | Quote | Price can be "from X" or on request. Has duration or SLA |

## Category tree (starting point)

- **Barcode scanners**: handheld (1D, 2D), wireless, stationary/presentation, embedded/OEM
- **Printers**: label (desktop, industrial, mobile), receipt, card printers
- **Mobile computers (TSD)**: Android TSD, wearable, RFID handheld
- **RFID**: fixed readers, handheld readers, antennas, tags and inlays, RFID printers
- **POS equipment**: POS terminals, fiscal registers, scales (with label printing), cash drawers, customer displays
- **Consumables**: thermal labels, thermal-transfer labels, ribbons (wax, wax-resin, resin), receipt paper, RFID labels
- **Software**: warehouse/inventory, POS, label design, MDM
- **Services**: repair, setup and integration, maintenance contracts, training

## Common spec attributes by category

These go in `catalog-module/references/spec-attributes.md`. Main ones:

- **Scanner**: scan engine (1D laser, 1D CCD, 2D imager), interface (USB, RS232, Bluetooth, 2.4 GHz), scan rate, IP rating, drop spec (m), symbologies
- **Label printer**: print method (DT or TT), resolution (203/300/600 dpi), print width (mm), speed (mm/s), max roll OD, interfaces, ribbon length
- **Receipt printer**: paper width (58/80 mm), speed, auto-cutter, interfaces
- **TSD**: OS (Android version), screen size, scan engine, RAM/storage, battery mAh, IP rating, RFID option
- **RFID**: frequency (UHF 860–960 MHz, HF 13.56 MHz, LF), protocol (EPC Gen2, ISO 15693), read range (m), number of ports
- **Label/consumable**: width × height (mm), core diameter (mm), labels per roll, material, adhesive, type (DT or TT)
- **Ribbon**: width (mm), length (m), type (wax, wax-resin, resin), ink side (in or out)

## Terminology (uz / ru / en)

| en | ru | uz |
|---|---|---|
| Barcode scanner | Сканер штрих-кода | Shtrix-kod skaneri |
| Label printer | Принтер этикеток | Yorliq printeri |
| Receipt printer | Чековый принтер | Chek printeri |
| Mobile computer | Терминал сбора данных (ТСД) | Ma'lumot yig'ish terminali (TSD) |
| Ribbon | Риббон | Ribbon |
| Labels | Этикетки | Yorliqlar |
| Quote request | Запрос коммерческого предложения | Tijorat taklifi so'rovi |
| Service / repair | Сервис / ремонт | Servis / ta'mirlash |

Brands commonly carried: Zebra, Honeywell, Datalogic, Newland, Urovo, Godex, TSC, Argox, Xprinter, Sunmi, Chainway. Treat `brands` as data and never hard-code them.
