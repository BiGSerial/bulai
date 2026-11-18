# Métodos chamados pelo JavaScript da página (window.BulaiNative): o R8 não pode renomear nem remover.
-keepclassmembers class br.com.vixforge.bulai.web.PonteNativa {
    @android.webkit.JavascriptInterface <methods>;
}
-keepattributes JavascriptInterface
