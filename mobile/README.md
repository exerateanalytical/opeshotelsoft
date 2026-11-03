# OpesHotel mobile (Flutter)

Guest and staff app for OpesHotelSoft. French by default, English in Settings.

## Run

```sh
cd mobile
flutter pub get
flutter gen-l10n        # also runs automatically on build
flutter run             # device or emulator
flutter test
flutter analyze
flutter build apk --debug   # needs the Android SDK (set ANDROID_HOME)
```

## Point it at the LAN server

1. Start Laravel so the LAN can reach it: `php artisan serve --host=0.0.0.0 --port=8000`.
2. Find the PC's IP (`ipconfig`), e.g. `192.168.1.189`, and allow port 8000 in the Windows firewall.
3. In the app, tap the gear icon (Settings) and set **Server address** to `http://192.168.1.189:8000`. Save.

Cleartext HTTP is allowed in the Android manifest for LAN use. Use HTTPS in production.

## Guest mode

- Enter the hotel group code (slug), search availability, book (public `/api/v1/booking/{group}/...`).
- My bookings: look up by confirmation number and last name; saved on the phone.
- Loyalty card: enter the member number; it is shown as a QR code.

## Staff mode

Sign in with the staff email and password (`POST /api/v1/auth/login`, returns a Passport token), then pick a hotel (sent as `X-Hotel-Id`).

- Housekeeping: my tasks for today, start and finish.
- Room status: set a room's housekeeping status.
- Work orders assigned to me: start and complete.
- Clock in/out, leave request, my payslips.

### Offline

Staff actions go through a local queue (sqflite). Each action gets an `Idempotency-Key` when it is taken; if the server cannot be reached it is stored and retried in order with the same key (on app resume, on screen open, or with "Sync now"). A gold banner shows how many actions are waiting. Actions the server refuses (4xx) are dropped and reported.
