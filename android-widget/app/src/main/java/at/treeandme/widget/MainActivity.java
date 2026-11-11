package at.treeandme.widget;

import android.Manifest;
import android.app.Activity;
import android.appwidget.AppWidgetManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.view.Gravity;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

/** Einrichtung: Standort erlauben, Widget auf den Startbildschirm legen. */
public class MainActivity extends Activity {
    private TextView info;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        LinearLayout l = new LinearLayout(this);
        l.setOrientation(LinearLayout.VERTICAL);
        l.setPadding(48, 96, 48, 48);
        l.setGravity(Gravity.CENTER_HORIZONTAL);
        TextView t = new TextView(this);
        t.setText("Tree and me – Widget");
        t.setTextSize(24);
        l.addView(t);
        info = new TextView(this);
        info.setTextSize(16);
        info.setPadding(0, 32, 0, 32);
        l.addView(info);
        Button perm = new Button(this);
        perm.setText("1. Standort erlauben");
        perm.setOnClickListener(x -> askPermissions());
        l.addView(perm);
        Button pin = new Button(this);
        pin.setText("2. Widget auf den Startbildschirm legen");
        pin.setOnClickListener(x -> pinWidget());
        l.addView(pin);
        Button open = new Button(this);
        open.setText("Tree and me öffnen");
        open.setOnClickListener(x -> startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(TreeWidget.APP_URL))));
        l.addView(open);
        setContentView(l);
    }

    @Override
    protected void onResume() {
        super.onResume();
        boolean ok = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
                || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
        info.setText((ok ? "✔ Standort erlaubt.\n\n" : "Bitte zuerst den Standort erlauben (\"Bei Nutzung der App\" reicht).\n\n")
                + "Das Widget zeigt die Bäume rund um dich auf einer Karte. Tippe auf die Karte, um sie zu aktualisieren.\n\n"
                + "● hellgrün = Laubbaum   ● dunkelgrün = Nadelbaum\n● rot = Obstbaum (essbar)   ● grau = nicht zugänglich");
    }

    private void askPermissions() {
        if (Build.VERSION.SDK_INT >= 33)
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION,
                    Manifest.permission.POST_NOTIFICATIONS}, 1);
        else
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, 1);
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] p, int[] r) { onResume(); }

    private void pinWidget() {
        AppWidgetManager m = getSystemService(AppWidgetManager.class);
        if (m != null && m.isRequestPinAppWidgetSupported())
            m.requestPinAppWidget(new ComponentName(this, TreeWidget.class), null, null);
        else
            info.setText("Dein Startbildschirm unterstützt das automatische Hinzufügen nicht. Lange auf eine freie Stelle tippen → Widgets → \"Tree and me Widget\".");
    }
}
