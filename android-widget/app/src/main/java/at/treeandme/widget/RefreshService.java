package at.treeandme.widget;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ServiceInfo;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Paint;
import android.location.Location;
import android.location.LocationManager;
import android.os.Build;
import android.os.CancellationSignal;
import android.os.IBinder;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Set;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.regex.Pattern;
import org.json.JSONArray;
import org.json.JSONObject;

/**
 * Wird durch Antippen des Widgets gestartet (Android erlaubt dann den Standortzugriff),
 * holt Standort, Bäume aus dem Wiener Baumkataster und OSM-Kacheln und zeichnet die Karte.
 */
public class RefreshService extends Service {
    static final int ZOOM = 17, SIZE = 640;   // ca. 500 m × 500 m in Wien
    static final String UA = "TreeAndMe-Widget/0.1 (+https://github.com/cpuhl51/tree-and-me)";
    private volatile boolean running;

    @Override public IBinder onBind(Intent i) { return null; }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        NotificationManager nm = getSystemService(NotificationManager.class);
        nm.createNotificationChannel(new NotificationChannel("refresh", "Widget aktualisieren", NotificationManager.IMPORTANCE_MIN));
        Notification n = new Notification.Builder(this, "refresh")
                .setSmallIcon(android.R.drawable.ic_menu_mylocation).setContentTitle("Lade Bäume in deiner Nähe …").build();
        try {
            if (Build.VERSION.SDK_INT >= 29) startForeground(7, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
            else startForeground(7, n);
        } catch (Exception e) {   // z. B. keine Standort-Erlaubnis
            TreeWidget.status(this, "Bitte App öffnen und Standort erlauben");
            stopSelf();
            return START_NOT_STICKY;
        }
        if (!running) {
            running = true;
            TreeWidget.status(this, "Suche Standort …");
            new Thread(() -> {
                try { refresh(); } catch (Exception e) { TreeWidget.status(this, "Fehler: " + e.getMessage()); }
                running = false;
                stopForeground(STOP_FOREGROUND_REMOVE);
                stopSelf();
            }).start();
        }
        return START_NOT_STICKY;
    }

    private void refresh() throws Exception {
        Location loc = currentLocation();
        if (loc == null) { TreeWidget.status(this, "Kein Standort – GPS eingeschaltet?"); return; }
        TreeWidget.status(this, "Lade Bäume …");

        // Kartenausschnitt in Weltpixeln (Web-Mercator)
        double cx = lonToX(loc.getLongitude()), cy = latToY(loc.getLatitude());
        double x0 = cx - SIZE / 2.0, y0 = cy - SIZE / 2.0;
        double west = xToLon(x0), east = xToLon(x0 + SIZE), north = yToLat(y0), south = yToLat(y0 + SIZE);

        Bitmap bmp = Bitmap.createBitmap(SIZE, SIZE, Bitmap.Config.ARGB_8888);
        Canvas c = new Canvas(bmp);
        c.drawColor(Color.rgb(230, 241, 231));
        drawTiles(c, x0, y0);
        Paint veil = new Paint(); veil.setColor(Color.argb(60, 255, 255, 255));
        c.drawRect(0, 0, SIZE, SIZE, veil);

        Rules rules = Rules.load(this);
        JSONArray feats = fetchTrees(west, south, east, north);
        Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG), ring = new Paint(Paint.ANTI_ALIAS_FLAG);
        ring.setColor(Color.WHITE); ring.setStyle(Paint.Style.STROKE); ring.setStrokeWidth(2.5f);
        int count = 0, playable = 0;
        for (int i = 0; i < feats.length(); i++) {
            JSONObject f = feats.getJSONObject(i), p = f.getJSONObject("properties");
            JSONArray co = f.getJSONObject("geometry").getJSONArray("coordinates");
            float px = (float) (lonToX(co.getDouble(0)) - x0), py = (float) (latToY(co.getDouble(1)) - y0);
            boolean open = !rules.closed.contains(p.optString("GEBIETSGRUPPE"));
            String kat = rules.category(p.optString("GATTUNG_ART"));
            fill.setColor(!open ? Color.rgb(160, 160, 160) : kat.equals("obst") ? Color.rgb(217, 58, 43)
                    : kat.equals("nadel") ? Color.rgb(31, 94, 44) : Color.rgb(124, 207, 90));
            c.drawCircle(px, py, 8, fill);
            c.drawCircle(px, py, 8, ring);
            count++; if (open) playable++;
        }
        // eigener Standort
        float ux = SIZE / 2f, uy = SIZE / 2f;
        Paint acc = new Paint(Paint.ANTI_ALIAS_FLAG); acc.setColor(Color.argb(40, 26, 115, 232));
        c.drawCircle(ux, uy, (float) Math.min(120, loc.getAccuracy() / metersPerPixel(loc.getLatitude())), acc);
        Paint me = new Paint(Paint.ANTI_ALIAS_FLAG); me.setColor(Color.WHITE); c.drawCircle(ux, uy, 13, me);
        me.setColor(Color.rgb(26, 115, 232)); c.drawCircle(ux, uy, 9, me);
        // Quellenangabe (Pflicht bei OSM und Stadt Wien)
        Paint t = new Paint(Paint.ANTI_ALIAS_FLAG); t.setTextSize(15); t.setColor(Color.argb(200, 60, 60, 60));
        c.drawText("© OpenStreetMap · Bäume: Stadt Wien (CC BY 4.0)", 8, SIZE - 8, t);

        try (FileOutputStream out = new FileOutputStream(new File(getFilesDir(), "widget.png"))) {
            bmp.compress(Bitmap.CompressFormat.PNG, 100, out);
        }
        String time = new SimpleDateFormat("HH:mm", Locale.GERMAN).format(new Date());
        String text = count == 0 ? "Keine Bäume hier – das Spiel gibt es nur in Wien · " + time
                : playable + " Bäume in der Nähe · " + time + " · tippen = neu";
        getSharedPreferences("w", 0).edit().putString("status", text).apply();
        TreeWidget.push(this, bmp, text);
    }

    // ---------- Standort ----------
    private Location currentLocation() throws InterruptedException {
        if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED
                && checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) != PackageManager.PERMISSION_GRANTED) return null;
        LocationManager lm = getSystemService(LocationManager.class);
        String provider = Build.VERSION.SDK_INT >= 31 && lm.hasProvider(LocationManager.FUSED_PROVIDER) ? LocationManager.FUSED_PROVIDER
                : lm.isProviderEnabled(LocationManager.GPS_PROVIDER) ? LocationManager.GPS_PROVIDER : LocationManager.NETWORK_PROVIDER;
        final Location[] result = {null};
        if (Build.VERSION.SDK_INT >= 30) {
            CountDownLatch done = new CountDownLatch(1);
            CancellationSignal cancel = new CancellationSignal();
            try {
                lm.getCurrentLocation(provider, cancel, Executors.newSingleThreadExecutor(), l -> { result[0] = l; done.countDown(); });
                if (!done.await(20, TimeUnit.SECONDS)) cancel.cancel();
            } catch (SecurityException e) { return null; }
        }
        if (result[0] == null) {   // Rückfall: letzter bekannter Standort (höchstens 10 Minuten alt)
            try {
                for (String p : lm.getProviders(true)) {
                    Location l = lm.getLastKnownLocation(p);
                    if (l != null && System.currentTimeMillis() - l.getTime() < 600_000
                            && (result[0] == null || l.getAccuracy() < result[0].getAccuracy())) result[0] = l;
                }
            } catch (SecurityException ignored) {}
        }
        return result[0];
    }

    // ---------- Daten ----------
    private JSONArray fetchTrees(double w, double s, double e, double n) throws Exception {
        String url = "https://data.wien.gv.at/daten/geo?service=WFS&request=GetFeature&version=1.0.0&typeName=ogdwien:BAUMKATOGD"
                + "&srsName=EPSG:4326&outputFormat=json&maxFeatures=2000"
                + String.format(Locale.US, "&bbox=%.6f,%.6f,%.6f,%.6f,EPSG:4326", w, s, e, n);
        JSONArray a = new JSONObject(new String(get(url), "UTF-8")).optJSONArray("features");
        return a == null ? new JSONArray() : a;
    }

    /** OSM-Kacheln zeichnen; Kacheln werden 7 Tage zwischengespeichert (OSM-Nutzungsregeln). */
    private void drawTiles(Canvas c, double x0, double y0) {
        int tx0 = (int) Math.floor(x0 / 256), ty0 = (int) Math.floor(y0 / 256);
        int tx1 = (int) Math.floor((x0 + SIZE) / 256), ty1 = (int) Math.floor((y0 + SIZE) / 256);
        File dir = new File(getCacheDir(), "tiles"); dir.mkdirs();
        for (int tx = tx0; tx <= tx1; tx++) for (int ty = ty0; ty <= ty1; ty++) {
            try {
                File f = new File(dir, ZOOM + "_" + tx + "_" + ty + ".png");
                if (!f.exists() || System.currentTimeMillis() - f.lastModified() > 7L * 86400_000) {
                    byte[] b = get("https://tile.openstreetmap.org/" + ZOOM + "/" + tx + "/" + ty + ".png");
                    try (FileOutputStream o = new FileOutputStream(f)) { o.write(b); }
                }
                Bitmap tile = BitmapFactory.decodeFile(f.getPath());
                if (tile != null) c.drawBitmap(tile, (float) (tx * 256 - x0), (float) (ty * 256 - y0), null);
            } catch (Exception ignored) { /* ohne Kachel weiter */ }
        }
    }

    private static byte[] get(String url) throws Exception {
        HttpURLConnection con = (HttpURLConnection) new URL(url).openConnection();
        con.setRequestProperty("User-Agent", UA);
        con.setConnectTimeout(15000); con.setReadTimeout(30000);
        if (con.getResponseCode() != 200) throw new Exception("HTTP " + con.getResponseCode());
        try (InputStream in = con.getInputStream(); ByteArrayOutputStream out = new ByteArrayOutputStream()) {
            byte[] buf = new byte[16384]; int r;
            while ((r = in.read(buf)) > 0) out.write(buf, 0, r);
            return out.toByteArray();
        }
    }

    // ---------- Web-Mercator ----------
    static double world() { return 256.0 * (1 << ZOOM); }
    static double lonToX(double lon) { return (lon + 180) / 360 * world(); }
    static double latToY(double lat) { double r = Math.toRadians(lat); return (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * world(); }
    static double xToLon(double x) { return x / world() * 360 - 180; }
    static double yToLat(double y) { double n = Math.PI - 2 * Math.PI * y / world(); return Math.toDegrees(Math.atan(Math.sinh(n))); }
    static double metersPerPixel(double lat) { return 156543.03392 * Math.cos(Math.toRadians(lat)) / (1 << ZOOM); }

    // ---------- Regeln aus regeln.json (wie in der Web-App) ----------
    static class Rules {
        final Set<String> closed = new HashSet<>(), nadel = new HashSet<>();
        final List<Pattern> obst = new ArrayList<>();
        static Rules load(Service s) {
            Rules r = new Rules();
            try (InputStream in = s.getAssets().open("regeln.json")) {
                JSONObject j = new JSONObject(new String(in.readAllBytes(), "UTF-8"));
                JSONArray a = j.getJSONArray("nicht_zugaenglich"); for (int i = 0; i < a.length(); i++) r.closed.add(a.getString(i));
                a = j.getJSONArray("nadel_gattungen"); for (int i = 0; i < a.length(); i++) r.nadel.add(a.getString(i));
                a = j.getJSONArray("obst_essbar"); for (int i = 0; i < a.length(); i++) r.obst.add(Pattern.compile(a.getString(i)));
            } catch (Exception ignored) {}
            return r;
        }
        String category(String art) {
            String a = art == null ? "" : art.trim();
            String genus = a.split(" ")[0].toLowerCase(Locale.ROOT);
            if (nadel.contains(genus)) return "nadel";
            for (Pattern p : obst) if (p.matcher(a).find()) return "obst";
            return "laub";
        }
    }
}
