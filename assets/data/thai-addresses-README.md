# Thailand address dropdown data

`thai-addresses.json` is a local, reduced snapshot of [kongvut/thai-province-data](https://github.com/kongvut/thai-province-data), under the included MIT license (`thai-addresses-LICENSE.txt`).

- Upstream commit: `326c2ebe778fc0c6a26c4b09770e3c2aa97c6be8`
- Source: `api/latest/province_with_district_and_sub_district.json`
- Retrieved: 2026-09-09
- Retained: province/district/subdistrict IDs and Thai names, and subdistrict ZIP codes. Records marked deleted are excluded. IDs and ZIP codes are strings.
- Snapshot counts: 77 provinces, 930 districts, 7,452 subdistricts.

The server validates the complete parent-child selection and builds `users.address_detail` from canonical names and the local ZIP code. Existing profile screens and reports continue reading that full address. Registration searches the entered address and selected area using the Photon geocoder after a 1.2-second typing pause. The endpoint is configured in `config/user_auth_view.php` via `data-geocoder`. Results are approximate and users can adjust the marker. Failed searches leave coordinates empty and do not prevent registration. Dropdown data and server validation remain local.
