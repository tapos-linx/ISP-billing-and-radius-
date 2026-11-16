package com.example.ui.theme

import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext

private val DarkColorScheme = darkColorScheme(
    primary = CyanPulse,
    onPrimary = TelecomNavyDark,
    primaryContainer = TelecomCardDark,
    onPrimaryContainer = CyanPulse,
    secondary = EmeraldSla,
    onSecondary = TelecomNavyDark,
    secondaryContainer = TelecomBorderDark,
    onSecondaryContainer = EmeraldSla,
    tertiary = AmberBilling,
    onTertiary = TelecomNavyDark,
    background = TelecomNavyDark,
    onBackground = TextPrimaryDark,
    surface = TelecomSurfaceDark,
    onSurface = TextPrimaryDark,
    surfaceVariant = TelecomCardDark,
    onSurfaceVariant = TextSecondaryDark,
    outline = TelecomBorderDark,
    error = CoralDisconnect,
    onError = Color.White
)

private val LightColorScheme = lightColorScheme(
    primary = CyanPulseDim,
    onPrimary = Color.White,
    primaryContainer = TelecomLightCard,
    onPrimaryContainer = CyanPulseDim,
    secondary = EmeraldSla,
    onSecondary = Color.White,
    secondaryContainer = TelecomLightCard,
    onSecondaryContainer = TextPrimaryLight,
    tertiary = AmberBilling,
    onTertiary = Color.White,
    background = TelecomLightBg,
    onBackground = TextPrimaryLight,
    surface = TelecomLightSurface,
    onSurface = TextPrimaryLight,
    surfaceVariant = TelecomLightCard,
    onSurfaceVariant = TextSecondaryLight,
    outline = TelecomLightBorder,
    error = CoralDisconnect,
    onError = Color.White
)

@Composable
fun MyApplicationTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false, // Keep telecom branding consistent by default
    content: @Composable () -> Unit
) {
    val colorScheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S -> {
            val context = LocalContext.current
            if (darkTheme) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        }
        darkTheme -> DarkColorScheme
        else -> DarkColorScheme // ISP Network Engineer tools look and feel best in high-contrast dark theme
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = Typography,
        content = content
    )
}
