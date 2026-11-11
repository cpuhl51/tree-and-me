package at.treeandme.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.Uri;
import android.os.Bundle;
import android.widget.RemoteViews;
import java.io.File;
import java.util.Locale;
import org.json.JSONArray;

/**
 * Startbildschirm-Widget. Widgets kennen keine Wisch- oder Zoom-Gesten, nur Tipp-Flächen:
 * Knöpfe für Zoom (− +), Verschieben (▲▼◀▶) und Neu laden (⟳); ein unsichtbares 6×6-Raster
 * über der Karte öffnet die App an dieser Stelle bzw. beim nächstgelegenen Baum.
 */
public class TreeWidget extends AppWidgetProvider {
    static final String APP_URL = "https://cpuhl51.github.io/tree-and-me/";
    static final int[] CELLS = {
        R.id.g0, R.id.g1, R.id.g2, R.id.g3, R.id.g4, R.id.g5, R.id.g6, R.id.g7, R.id.g8, R.id.g9, R.id.g10, R.id.g11,
        R.id.g12, R.id.g13, R.id.g14, R.id.g15, R.id.g16, R.id.g17, R.id.g18, R.id.g19, R.id.g20, R.id.g21, R.id.g22, R.id.g23,
        R.id.g24, R.id.g25, R.id.g26, R.id.g27, R.id.g28, R.id.g29, R.id.g30, R.id.g31, R.id.g32, R.id.g33, R.id.g34, R.id.g35 };

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) { pushCached(ctx); }

    @Override
    public void onAppWidgetOptionsChanged(Context ctx, AppWidgetManager mgr, int id, Bundle opts) { pushCached(ctx); }

    static SharedPreferences prefs(Context ctx) { return ctx.getSharedPreferences("w", 0); }

    static void pushCached(Context ctx) {
        File f = new File(ctx.getFilesDir(), "widget.png");
        push(ctx, f.exists() ? BitmapFactory.decodeFile(f.getPath()) : null, prefs(ctx).getString("status", "⟳ tippen zum Laden"));
    }

    static void status(Context ctx, String text) {
        prefs(ctx).edit().putString("status", text).apply();
        pushCached(ctx);
    }

    private static PendingIntent action(Context ctx, int code, String action, float dx, float dy) {
        Intent i = new Intent(ctx, RefreshService.class).setAction(action).putExtra("dx", dx).putExtra("dy", dy);
        return PendingIntent.getForegroundService(ctx, code, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static PendingIntent open(Context ctx, int code, String url) {
        Intent i = new Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        return PendingIntent.getActivity(ctx, code, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /** Baut die Widget-Ansicht mit allen Tipp-Flächen und schickt sie an alle Widgets dieser App. */
    static void push(Context ctx, Bitmap bmp, String status) {
        SharedPreferences p = prefs(ctx);
        RemoteViews v = new RemoteViews(ctx.getPackageName(), R.layout.widget);
        if (bmp != null) v.setImageViewBitmap(R.id.map, bmp);
        v.setTextViewText(R.id.status, status);
        v.setOnClickPendingIntent(R.id.refresh, action(ctx, 10, "refresh", 0, 0));
        v.setOnClickPendingIntent(R.id.status, action(ctx, 11, "refresh", 0, 0));
        v.setOnClickPendingIntent(R.id.zoomIn, action(ctx, 12, "zoom", 1, 0));
        v.setOnClickPendingIntent(R.id.zoomOut, action(ctx, 13, "zoom", -1, 0));
        v.setOnClickPendingIntent(R.id.panN, action(ctx, 14, "pan", 0, -1));
        v.setOnClickPendingIntent(R.id.panS, action(ctx, 15, "pan", 0, 1));
        v.setOnClickPendingIntent(R.id.panW, action(ctx, 16, "pan", -1, 0));
        v.setOnClickPendingIntent(R.id.panE, action(ctx, 17, "pan", 1, 0));
        String here = p.contains("lat") ? String.format(Locale.US, "%s?lat=%.6f&lon=%.6f&z=%d", APP_URL,
                p.getFloat("lat", 0), p.getFloat("lon", 0), Math.max(16, p.getInt("zoom", 17))) : APP_URL;
        v.setOnClickPendingIntent(R.id.open, open(ctx, 20, here));
        v.setOnClickPendingIntent(R.id.title, open(ctx, 21, here));
        // Tipp-Raster: Ziel-Links stehen nach dem letzten Zeichnen in den Einstellungen
        JSONArray cells = null;
        try { cells = new JSONArray(p.getString("cells", "[]")); } catch (Exception ignored) {}
        for (int i = 0; i < CELLS.length; i++) {
            String url = cells != null && i < cells.length() ? cells.optString(i, null) : null;
            v.setOnClickPendingIntent(CELLS[i], url != null ? open(ctx, 100 + i, url) : action(ctx, 200 + i, "refresh", 0, 0));
        }
        AppWidgetManager.getInstance(ctx).updateAppWidget(new ComponentName(ctx, TreeWidget.class), v);
    }
}
