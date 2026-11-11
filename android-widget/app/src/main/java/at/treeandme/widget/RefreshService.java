package at.treeandme.widget;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.appwidget.AppWidgetManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.SharedPreferences;
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
import android.os.Bundle;
import android.os.CancellationSignal;
import android.os.IBinder;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.file.Files;
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
 * Wird durch Antippen im Widget gestartet. „refresh“ holt den Standort (Android erlaubt das bei
 * Widget-Interaktion), „zoom“ und „pan“ verschieben nur den Ausschnitt. Bäume und Kacheln werden
 * zwischengespeichert, damit Zoomen und Verschieben schnell geht.
 */
public class RefreshService extends Service {
    static final int MIN_Z = 16, MAX_Z = 19;
    static final String UA = "TreeAndMe-Widget/0.2 (+https://github.com/cpuhl51/tree-and-me)";
    private volatile boolean running;
    private int z;   // Zoomstufe der aktuellen Zeichnung

    @Override public IBinder onBind(Intent i) { return null; }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null && intent.getAction() != null ? intent.getAction() : "refresh";
        boolean locate = action.equals("refresh");
        NotificationManager nm = getSystemService(NotificationManager.class);
        nm.createNotificationChannel(new NotificationChannel("refresh", "Widget aktualisieren", NotificationManager.IMPORTANCE_MIN));
        Notification n = new Notification.Builder(this, "refresh")
                .setSmallIcon(android.R.drawable.ic_menu_mapmode).setContentTitle("Tree and me: Karte wird geladen …").build();
        try {
            if (Build.VERSION.SDK_INT >= 29)
                startForeground(7, n, locate ? ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION : ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
            else startForeground(7, n);
        } catch (Exception e) {
            TreeWidget.status(this, locate ? "Bitte App öffnen und Standort erlauben" : "Fehler: " + e.getMessage());
            stopSelf();
            return START_NOT_STICKY;
        }
        if (running) return START_NOT_STICKY;   // läuft schon – weiteres Tippen ignorieren
        running = true;
        float dx = intent != null ? intent.getFloatExtra("dx", 0) : 0, dy = intent != null ? intent.getFloatExtra("dy", 0) : 0;
        new Thread(() -> {
            try {
                SharedPreferences.Editor e = TreeWidget.prefs(this).edit();
                SharedPreferences p = TreeWidget.prefs(this);
                if (locate) {
                    TreeWidget.status(this, "Suche Standort …");
                    Location loc = currentLocation();
                    if (loc == null) { TreeWidget.status(this, "Kein Standort – GPS an? ⟳ nochmal"); return; }
                    e.putFloat("lat", (float) loc.getLatitude()).putFloat("lon", (float) loc.getLongitude())
                     .putFloat("uLat", (float) loc.getLatitude()).putFloat("uLon", (float) loc.getLongitude())
                     .putFloat("uAcc", loc.getAccuracy()).putLong("uTime", System.currentTimeMillis()).apply();
                } else if (!p.contains("lat")) {
                    TreeWidget.status(this, "Zuerst ⟳ tippen"); return;
                } else if (action.equals("zoom")) {
                    e.putInt("zoom", Math.max(MIN_Z, Math.min(MAX_Z, p.getInt("zoom", 17) + (int) dx))).apply();
                } else if (action.equals("pan")) {
                    int zz = p.getInt("zoom", 17); int[] wh = size();
                    double cx = lonToX(p.getFloat("lon", 0), zz) + dx * wh[0] / 2.5, cy = latToY(p.getFloat("lat", 0), zz) + dy * wh[1] / 2.5;
                    e.putFloat("lon", (float) xToLon(cx, zz)).putFloat("lat", (float) yToLat(cy, zz)).apply();
                }
                render();
            } catch (Exception ex) {
                TreeWidget.status(this, ex.getMessage() != null && ex.getMessage().startsWith("Stadt") ? ex.getMessage() : "Fehler: " + ex.getMessage() + " – ⟳ nochmal");
            } finally {
                running = false;
                stopForeground(STOP_FOREGROUND_REMOVE);
                stopSelf();
            }
        }).start();
        return START_NOT_STICKY;
    }

    /** Größe der Karte in Pixeln passend zum Widget (2 px je dp, höchstens 900 px). */
    private int[] size() {
        AppWidgetManager m = AppWidgetManager.getInstance(this);
        int[] ids = m.getAppWidgetIds(new ComponentName(this, TreeWidget.class));
        int wDp = 300, hDp = 300;
        if (ids.length > 0) {
            Bundle o = m.getAppWidgetOptions(ids[0]);
            wDp = Math.max(150, o.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_WIDTH, 300));
            hDp = Math.max(150, o.getInt(AppWidgetManager.OPTION_APPWIDGET_MAX_HEIGHT, 300)) - 46;   // minus Kopfzeile
        }
        double k = Math.min(2.0, 900.0 / Math.max(wDp, hDp));
        return new int[]{(int) (wDp * k), (int) (hDp * k)};
    }

    private void render() throws Exception {
        SharedPreferences p = TreeWidget.prefs(this);
        z = p.getInt("zoom", 17);
        int[] wh = size(); int W = wh[0], H = wh[1];
        double x0 = lonToX(p.getFloat("lon", 0), z) - W / 2.0, y0 = latToY(p.getFloat("lat", 0), z) - H / 2.0;
        double west = xToLon(x0, z), east = xToLon(x0 + W, z), north = yToLat(y0, z), south = yToLat(y0 + H, z);

        TreeWidget.status(this, "Lade Bäume …");
        JSONArray trees = treesFor(west, south, east, north);

        Bitmap bmp = Bitmap.createBitmap(W, H, Bitmap.Config.ARGB_8888);
        Canvas c = new Canvas(bmp);
        c.drawColor(Color.rgb(230, 241, 231));
        drawTiles(c, x0, y0, W, H);
        Paint veil = new Paint(); veil.setColor(Color.argb(60, 255, 255, 255));
        c.drawRect(0, 0, W, H, veil);

        float r = z >= 19 ? 11 : z == 18 ? 9 : z == 17 ? 7 : z == 16 ? 5 : 3.5f;
        Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG), ring = new Paint(Paint.ANTI_ALIAS_FLAG);
        ring.setColor(Color.WHITE); ring.setStyle(Paint.Style.STROKE); ring.setStrokeWidth(r > 5 ? 2.5f : 1.2f);
        // für das Tipp-Raster: je Zelle der nächstgelegene (bevorzugt spielbare) Baum
        int[] best = new int[36]; double[] bestD = new double[36];
        java.util.Arrays.fill(best, -1); java.util.Arrays.fill(bestD, Double.MAX_VALUE);
        int visible = 0;
        for (int i = 0; i < trees.length(); i++) {
            JSONArray t = trees.getJSONArray(i);   // [lon, lat, baum_id, kat, offen]
            float px = (float) (lonToX(t.getDouble(0), z) - x0), py = (float) (latToY(t.getDouble(1), z) - y0);
            if (px < -r || py < -r || px > W + r || py > H + r) continue;
            boolean open = t.getInt(4) == 1; String kat = t.getString(3);
            fill.setColor(!open ? Color.rgb(160, 160, 160) : kat.equals("obst") ? Color.rgb(217, 58, 43)
                    : kat.equals("nadel") ? Color.rgb(31, 94, 44) : Color.rgb(124, 207, 90));
            c.drawCircle(px, py, r, fill);
            c.drawCircle(px, py, r, ring);
            if (open) visible++;
            int col = Math.min(5, Math.max(0, (int) (px / W * 6))), row = Math.min(5, Math.max(0, (int) (py / H * 6))), cell = row * 6 + col;
            double ccx = (col + 0.5) * W / 6, ccy = (row + 0.5) * H / 6, d = Math.hypot(px - ccx, py - ccy) + (open ? 0 : 1e6);
            if (d < bestD[cell]) { bestD[cell] = d; best[cell] = i; }
        }
        // eigener Standort (wenn im Ausschnitt und nicht älter als 1 Stunde)
        if (p.contains("uLat") && System.currentTimeMillis() - p.getLong("uTime", 0) < 3600_000) {
            float ux = (float) (lonToX(p.getFloat("uLon", 0), z) - x0), uy = (float) (latToY(p.getFloat("uLat", 0), z) - y0);
            if (ux > 0 && uy > 0 && ux < W && uy < H) {
                Paint acc = new Paint(Paint.ANTI_ALIAS_FLAG); acc.setColor(Color.argb(40, 26, 115, 232));
                c.drawCircle(ux, uy, (float) Math.min(150, p.getFloat("uAcc", 20) / mpp(p.getFloat("uLat", 48.2f), z)), acc);
                Paint me = new Paint(Paint.ANTI_ALIAS_FLAG); me.setColor(Color.WHITE); c.drawCircle(ux, uy, 13, me);
                me.setColor(Color.rgb(26, 115, 232)); c.drawCircle(ux, uy, 9, me);
            }
        }
        Paint t = new Paint(Paint.ANTI_ALIAS_FLAG); t.setTextSize(15); t.setColor(Color.argb(200, 60, 60, 60));
        c.drawText("© OpenStreetMap · Bäume: Stadt Wien", 8, H - 8, t);

        // Tipp-Raster: Baum öffnen, sonst die App an dieser Stelle
        JSONArray cells = new JSONArray();
        for (int i = 0; i < 36; i++) {
            if (best[i] >= 0 && bestD[i] < 1e6) {
                JSONArray tr = trees.getJSONArray(best[i]);
                cells.put(String.format(Locale.US, "%s?lat=%.6f&lon=%.6f&z=19&baum=%s", TreeWidget.APP_URL, tr.getDouble(1), tr.getDouble(0), tr.getString(2)));
            } else {
                double cx = x0 + ((i % 6) + 0.5) * W / 6, cy = y0 + ((i / 6) + 0.5) * H / 6;
                cells.put(String.format(Locale.US, "%s?lat=%.6f&lon=%.6f&z=19", TreeWidget.APP_URL, yToLat(cy, z), xToLon(cx, z)));
            }
        }
        try (FileOutputStream out = new FileOutputStream(new File(getFilesDir(), "widget.png"))) {
            bmp.compress(Bitmap.CompressFormat.PNG, 100, out);
        }
        String time = new SimpleDateFormat("HH:mm", Locale.GERMAN).format(new Date());
        String text = trees.length() == 0 ? "Keine Bäume – nur in Wien · " + time : visible + " Bäume · " + time;
        p.edit().putString("status", text).putString("cells", cells.toString()).apply();
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
        if (result[0] == null) {
            try {
                for (String pr : lm.getProviders(true)) {
                    Location l = lm.getLastKnownLocation(pr);
                    if (l != null && System.currentTimeMillis() - l.getTime() < 600_000
                            && (result[0] == null || l.getAccuracy() < result[0].getAccuracy())) result[0] = l;
                }
            } catch (SecurityException ignored) {}
        }
        return result[0];
    }

    // ---------- Bäume (zwischengespeichert, 30 Minuten) ----------
    private JSONArray treesFor(double w, double s, double e, double n) throws Exception {
        SharedPreferences p = TreeWidget.prefs(this);
        File cache = new File(getFilesDir(), "trees.json");
        boolean fresh = System.currentTimeMillis() - p.getLong("tTime", 0) < 1800_000;
        if (fresh && cache.exists() && p.getFloat("tW", 999) <= w && p.getFloat("tS", 999) <= s
                && p.getFloat("tE", -999) >= e && p.getFloat("tN", -999) >= n)
            return new JSONArray(new String(Files.readAllBytes(cache.toPath()), "UTF-8"));
        // etwas größeren Bereich laden, damit Verschieben ohne Nachladen geht
        double mw = (e - w) * 0.6, mh = (n - s) * 0.6;
        double W = w - mw, S = s - mh, E = e + mw, N = n + mh;
        String url = "https://data.wien.gv.at/daten/geo?service=WFS&request=GetFeature&version=1.0.0&typeName=ogdwien:BAUMKATOGD"
                + "&srsName=EPSG:4326&outputFormat=json&maxFeatures=5000"
                + String.format(Locale.US, "&bbox=%.6f,%.6f,%.6f,%.6f,EPSG:4326", W, S, E, N);
        JSONArray feats;
        try {
            JSONObject j = new JSONObject(new String(getRetry(url, 3), "UTF-8"));
            feats = j.optJSONArray("features"); if (feats == null) feats = new JSONArray();
        } catch (Exception ex) { throw new Exception("Stadt-Server gerade nicht erreichbar – ⟳ nochmal"); }
        Rules rules = Rules.load(this);
        JSONArray out = new JSONArray();
        for (int i = 0; i < feats.length(); i++) {
            JSONObject f = feats.getJSONObject(i), pr = f.getJSONObject("properties");
            JSONArray co = f.getJSONObject("geometry").getJSONArray("coordinates");
            out.put(new JSONArray().put(co.getDouble(0)).put(co.getDouble(1)).put(pr.optString("BAUM_ID"))
                    .put(rules.category(pr.optString("GATTUNG_ART"))).put(rules.closed.contains(pr.optString("GEBIETSGRUPPE")) ? 0 : 1));
        }
        try (FileOutputStream o = new FileOutputStream(cache)) { o.write(out.toString().getBytes("UTF-8")); }
        p.edit().putLong("tTime", System.currentTimeMillis()).putFloat("tW", (float) W).putFloat("tS", (float) S)
                .putFloat("tE", (float) E).putFloat("tN", (float) N).apply();
        return out;
    }

    /** OSM-Kacheln, 7 Tage zwischengespeichert (OSM-Nutzungsregeln). */
    private void drawTiles(Canvas c, double x0, double y0, int W, int H) {
        int tx0 = (int) Math.floor(x0 / 256), ty0 = (int) Math.floor(y0 / 256);
        int tx1 = (int) Math.floor((x0 + W) / 256), ty1 = (int) Math.floor((y0 + H) / 256);
        File dir = new File(getCacheDir(), "tiles"); dir.mkdirs();
        for (int tx = tx0; tx <= tx1; tx++) for (int ty = ty0; ty <= ty1; ty++) {
            try {
                File f = new File(dir, z + "_" + tx + "_" + ty + ".png");
                if (!f.exists() || System.currentTimeMillis() - f.lastModified() > 7L * 86400_000) {
                    byte[] b = getRetry("https://tile.openstreetmap.org/" + z + "/" + tx + "/" + ty + ".png", 2);
                    try (FileOutputStream o = new FileOutputStream(f)) { o.write(b); }
                }
                Bitmap tile = BitmapFactory.decodeFile(f.getPath());
                if (tile != null) c.drawBitmap(tile, (float) (tx * 256 - x0), (float) (ty * 256 - y0), null);
            } catch (Exception ignored) {}
        }
    }

    /** Der Geodienst der Stadt Wien antwortet gelegentlich mit HTTP 500 – dann kurz warten und nochmal. */
    private static byte[] getRetry(String url, int tries) throws Exception {
        for (int i = 1; ; i++) {
            try { return get(url); }
            catch (Exception e) { if (i >= tries) throw e; Thread.sleep(800L * i); }
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
    static double world(int z) { return 256.0 * (1 << z); }
    static double lonToX(double lon, int z) { return (lon + 180) / 360 * world(z); }
    static double latToY(double lat, int z) { double r = Math.toRadians(lat); return (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * world(z); }
    static double xToLon(double x, int z) { return x / world(z) * 360 - 180; }
    static double yToLat(double y, int z) { double n = Math.PI - 2 * Math.PI * y / world(z); return Math.toDegrees(Math.atan(Math.sinh(n))); }
    static double mpp(double lat, int z) { return 156543.03392 * Math.cos(Math.toRadians(lat)) / (1 << z); }

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
            if (nadel.contains(a.split(" ")[0].toLowerCase(Locale.ROOT))) return "nadel";
            for (Pattern p : obst) if (p.matcher(a).find()) return "obst";
            return "laub";
        }
    }
}
