import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  BackHandler,
  Linking,
  SafeAreaView,
  StatusBar,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { WebView } from 'react-native-webview';

const APP_URL = 'https://scraping-olive-eta.vercel.app';
const APP_HOST = new URL(APP_URL).hostname;

export default function App() {
  const webView = useRef(null);
  const canGoBack = useRef(false);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  const goBack = useCallback(() => {
    if (canGoBack.current) {
      webView.current?.goBack();
      return true;
    }
    return false;
  }, []);

  useEffect(() => {
    const subscription = BackHandler.addEventListener('hardwareBackPress', goBack);
    return () => subscription.remove();
  }, [goBack]);

  const keepAppNavigation = useCallback((request) => {
    try {
      const url = new URL(request.url);
      if (url.hostname === APP_HOST || url.hostname.endsWith('.vercel.app')) return true;
      if (url.protocol === 'https:' || url.protocol === 'http:' || url.protocol === 'mailto:') {
        Linking.openURL(request.url).catch(() => {});
      }
      return false;
    } catch {
      return false;
    }
  }, []);

  return (
    <SafeAreaView style={styles.screen}>
      <StatusBar barStyle="light-content" backgroundColor="#08111f" />
      <WebView
        ref={webView}
        source={{ uri: APP_URL }}
        style={styles.webView}
        javaScriptEnabled
        domStorageEnabled
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        setSupportMultipleWindows={false}
        onShouldStartLoadWithRequest={keepAppNavigation}
        onNavigationStateChange={(state) => { canGoBack.current = state.canGoBack; }}
        onLoadStart={() => { setLoading(true); setFailed(false); }}
        onLoadEnd={() => setLoading(false)}
        onError={() => { setLoading(false); setFailed(true); }}
        onHttpError={(event) => {
          if (event.nativeEvent.statusCode >= 500) setFailed(true);
        }}
        renderLoading={() => null}
        startInLoadingState
        allowsBackForwardNavigationGestures
      />
      {(loading || failed) && (
        <View style={styles.overlay}>
          {failed ? (
            <>
              <Text style={styles.title}>No se pudo abrir Radar</Text>
              <Text style={styles.message}>Revisa tu conexión e inténtalo de nuevo.</Text>
              <TouchableOpacity
                accessibilityRole="button"
                style={styles.button}
                onPress={() => webView.current?.reload()}
              >
                <Text style={styles.buttonText}>Reintentar</Text>
              </TouchableOpacity>
            </>
          ) : (
            <ActivityIndicator size="large" color="#65a9ff" />
          )}
        </View>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#08111f' },
  webView: { flex: 1, backgroundColor: '#08111f' },
  overlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    padding: 28,
    backgroundColor: '#08111f',
  },
  title: { color: '#f4f7fb', fontSize: 19, fontWeight: '700', textAlign: 'center' },
  message: { color: '#aab9ce', fontSize: 15, marginTop: 10, textAlign: 'center' },
  button: { marginTop: 22, backgroundColor: '#1767b4', borderRadius: 10, paddingHorizontal: 22, paddingVertical: 12 },
  buttonText: { color: '#fff', fontSize: 15, fontWeight: '600' },
});
