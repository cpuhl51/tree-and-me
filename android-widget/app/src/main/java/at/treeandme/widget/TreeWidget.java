package at.treeandme.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.Uri;
import android.widget.RemoteViews;
import java.io.File;

/** Startbildschirm-Widget: zeigt die zuletzt geladene Karte; Antippen lädt neu. */
public class TreeWidget extends AppWidgetProvider {
    static final String APP_URL = "https://cpuhl51.github.io/tree-and-me/";

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        File f = new File(ctx.getFilesDir(), "widget.png");
        Bitmap bmp = f.exists() ? BitmapFactory.decodeFile(f.getPath()) : null;
        String status = ctx.getSharedPreferences("w", 0).getString("status", "Antippen zum Aktualisieren");
        push(ctx, bmp, status);
    }

    /** Baut die Widget-Ansicht und schickt sie an alle Widgets dieser App. */
    static void push(Context ctx, Bitmap bmp, String status) {
        RemoteViews v = new RemoteViews(ctx.getPackageName(), R.layout.widget);
        if (bmp != null) v.setImageViewBitmap(R.id.map, bmp);
        v.setTextViewText(R.id.status, status);
        Intent refresh = new Intent(ctx, RefreshService.class);
        PendingIntent pRefresh = PendingIntent.getForegroundService(ctx, 1, refresh,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        v.setOnClickPendingIntent(R.id.map, pRefresh);
        v.setOnClickPendingIntent(R.id.status, pRefresh);
        Intent open = new Intent(Intent.ACTION_VIEW, Uri.parse(APP_URL));
        v.setOnClickPendingIntent(R.id.open, PendingIntent.getActivity(ctx, 2, open, PendingIntent.FLAG_IMMUTABLE));
        v.setOnClickPendingIntent(R.id.title, PendingIntent.getActivity(ctx, 3, open, PendingIntent.FLAG_IMMUTABLE));
        AppWidgetManager.getInstance(ctx).updateAppWidget(new ComponentName(ctx, TreeWidget.class), v);
    }

    static void status(Context ctx, String text) {
        ctx.getSharedPreferences("w", 0).edit().putString("status", text).apply();
        File f = new File(ctx.getFilesDir(), "widget.png");
        push(ctx, f.exists() ? BitmapFactory.decodeFile(f.getPath()) : null, text);
    }
}
