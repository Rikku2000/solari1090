# Solari1090

A lightweight Solari-style (flip-board) arrivals and departures display powered by **dump1090 / SkyAware** ADS-B data.  
Designed for local receivers to show nearby aircraft in a clean airport-style board.

## Features

- Arrivals and Departures views  
- Uses dump1090 `aircraft.json` feed  
- Airline guess from callsign prefix  
- Shows altitude, distance, ground speed, climb/descend trend  
- Optional status labels (TAKE OFF / LANDING / LANDED / LOW ALT)  
- Simple PHP backend — no database required  
- No build step (plain PHP + static assets)  
- **Admin panel for configuration via browser**

## Requirements

- PHP 7.4+ compatible web server  
- dump1090 / SkyAware instance with accessible JSON feed  

## Installation

1. Copy the project files to your web server directory.
2. Edit **`config.php`**:
   - Set your dump1090/SkyAware base URL
   - Set airport name and coordinates
   - Set the admin password
3. Ensure the server can write the cache file (`state_cache.json`).
4. Open `index.php` in your browser.

## Admin Panel

The project includes a simple password-protected admin interface.

### Access

Open:

```
admin.php
```

### Login

- Uses a single password defined in `config.php`
- No username required
- Session-based authentication

Example in `config.php`:

```php
$config['admin_password'] = 'changeme';
```

⚠️ Change this password before deploying publicly.

### Features

The admin panel allows you to:

- Edit configuration values directly in the browser
- Update receiver URL and airport settings
- Adjust display thresholds and limits
- Save changes back to `config.php`
- Log out securely

No database is used — all settings remain file-based.

## Configuration

All settings are located in `config.php`, including:

- Airport location  
- Display radius  
- Altitude limits  
- Arrival/departure thresholds  
- Status detection options  
- Cache file location  
- Admin password  

Adjust these values to match your receiver location and desired coverage.

## API

The frontend retrieves data from:

- `api.php?mode=departures`
- `api.php?mode=arrivals`

The API fetches aircraft data from dump1090, filters nearby traffic, determines trend-based movement, and returns formatted rows for display.

## Notes

- Results depend on receiver placement and coverage.
- Threshold tuning may be required for best classification.
- Designed for hobbyist ADS-B setups.
