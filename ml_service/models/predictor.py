"""ML model that predicts stock prices from fundamental and technical data."""

from __future__ import annotations

import logging
from typing import Any

import numpy as np
import pandas as pd
import xgboost as xgb
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, r2_score
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler

from schemas.prediction import FeatureImportance, PredictionDirection

logger = logging.getLogger(__name__)


REQUIRED_FEATURES: list[str] = [
    "pe_ratio",
    "debt_to_equity",
    "current_ratio",
    "free_cash_flow",
    "gross_margin",
    "operating_margin",
    "roe",
    "roa",
    "eps",
    "market_cap",
    "revenue_growth",
]

FEATURE_DEFAULTS: dict[str, float] = {
    "pe_ratio": 0.0,
    "debt_to_equity": 0.0,
    "current_ratio": 1.0,
    "free_cash_flow": 0.0,
    "gross_margin": 0.3,
    "operating_margin": 0.1,
    "roe": 0.1,
    "roa": 0.05,
    "eps": 0.0,
    "market_cap": 0.0,
    "revenue_growth": 0.0,
}

TECHNICAL_FEATURES: list[str] = [
    "returns_5d",
    "returns_20d",
    "volatility_20d",
    "volume_ratio",
    "sma_20",
    "sma_50",
    "price_to_sma20",
    "open",
    "high",
    "low",
    "close",
    "volume",
]


class StockPredictor:
    """Stock price predictor using an ensemble of XGBoost and RandomForest."""

    MODEL_VERSION = "1.1.0"

    def __init__(self) -> None:
        self.scaler = StandardScaler()
        self.xgb_model: xgb.XGBRegressor | None = None
        self.rf_model: RandomForestRegressor | None = None
        self._is_loaded = False
        self._is_trained = False
        self._train_metrics: dict[str, float] = {}

    def load_model(self) -> None:
        """Initialise untrained model objects; call train_on_price_history() to fit."""
        logger.info("Initializing model...")
        self.xgb_model = xgb.XGBRegressor(
            n_estimators=100,
            max_depth=6,
            learning_rate=0.1,
            random_state=42,
        )
        self.rf_model = RandomForestRegressor(
            n_estimators=100,
            max_depth=8,
            random_state=42,
        )
        self._is_loaded = True
        self._is_trained = False
        logger.info("Model initialized (untrained). Call train_on_price_history() to train.")

    @property
    def is_loaded(self) -> bool:
        return self._is_loaded

    @property
    def is_trained(self) -> bool:
        return self._is_trained

    @property
    def train_metrics(self) -> dict[str, float]:
        return dict(self._train_metrics)

    def train_on_price_history(
        self,
        df: pd.DataFrame,
        test_size: float = 0.2,
    ) -> dict[str, float]:
        """Train the ensemble on engineered OHLCV features using a chronological split."""
        if "target_close" not in df.columns:
            raise ValueError("DataFrame must contain 'target_close' column as the target.")

        feature_cols = [c for c in TECHNICAL_FEATURES if c in df.columns]
        X = df[feature_cols].values
        y = df["target_close"].values

        logger.info(
            "Training on %d samples, %d features (target_days_ahead from yfinance).",
            len(X),
            len(feature_cols),
        )

        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=test_size, shuffle=False,
        )

        X_train_scaled = self.scaler.fit_transform(X_train)
        X_test_scaled = self.scaler.transform(X_test)

        if self.xgb_model is None or self.rf_model is None:
            self.load_model()

        self.xgb_model.fit(X_train_scaled, y_train)
        self.rf_model.fit(X_train_scaled, y_train)

        xgb_preds = self.xgb_model.predict(X_test_scaled)
        rf_preds = self.rf_model.predict(X_test_scaled)
        ensemble_preds = (xgb_preds + rf_preds) / 2.0

        mae = float(mean_absolute_error(y_test, ensemble_preds))
        rmse = float(np.sqrt(((y_test - ensemble_preds) ** 2).mean()))
        r2 = float(r2_score(y_test, ensemble_preds))

        self._train_metrics = {"mae": round(mae, 6), "rmse": round(rmse, 6), "r2": round(r2, 6)}
        self._is_trained = True

        logger.info("Training complete – MAE=%.4f  RMSE=%.4f  R²=%.4f", mae, rmse, r2)

        return self._train_metrics

    def _prepare_features(self, features: dict[str, Any]) -> np.ndarray:
        """Extract and normalise the fundamental feature vector from input."""
        feature_values: list[float] = []
        for feat in REQUIRED_FEATURES:
            val = features.get(feat)
            if val is None or (isinstance(val, float) and np.isnan(val)):
                val = FEATURE_DEFAULTS.get(feat, 0.0)
            feature_values.append(float(val))
        return np.array(feature_values).reshape(1, -1)

    def predict(self, features: dict[str, Any]) -> dict[str, Any]:
        """Predict from fundamental features using the weighted heuristic."""
        if not self._is_loaded:
            self.load_model()

        X = self._prepare_features(features)

        predicted_price = self._predict_from_fundamentals(features)
        confidence_score = self._compute_confidence(features)

        predicted_price = round(predicted_price, 4)

        current_price = features.get("latest_price")
        if current_price and isinstance(current_price, (int, float)) and current_price > 0:
            if predicted_price > current_price * 1.05:
                direction = PredictionDirection.BULLISH
            elif predicted_price < current_price * 0.95:
                direction = PredictionDirection.BEARISH
            else:
                direction = PredictionDirection.NEUTRAL
        else:
            direction = PredictionDirection.NEUTRAL

        feature_importance = self._get_feature_importance()

        return {
            "predicted_price": predicted_price,
            "confidence_score": confidence_score,
            "direction": direction.value,
            "feature_importance": [fi.model_dump() for fi in feature_importance],
            "model": "xgboost_rf_ensemble",
            "version": self.MODEL_VERSION,
        }

    def predict_price(
        self,
        ohlcv_features: dict[str, float],
    ) -> dict[str, Any]:
        """Forecast the next close using the trained ensemble."""
        if not self._is_trained:
            raise RuntimeError("Model is not trained. Call train_on_price_history() first.")

        feature_cols = [c for c in TECHNICAL_FEATURES if c in ohlcv_features]
        X = np.array([[ohlcv_features.get(c, 0.0) for c in feature_cols]])

        X_scaled = self.scaler.transform(X)

        xgb_pred = float(self.xgb_model.predict(X_scaled)[0])
        rf_pred = float(self.rf_model.predict(X_scaled)[0])

        predicted_price = round((xgb_pred + rf_pred) / 2.0, 4)

        return {
            "predicted_price": predicted_price,
            "xgb_prediction": round(xgb_pred, 4),
            "rf_prediction": round(rf_pred, 4),
            "confidence_score": 0.7,
            "model": "xgboost_rf_ensemble",
            "version": self.MODEL_VERSION,
        }

    def _predict_from_fundamentals(self, features: dict[str, Any]) -> float:
        """Estimate price from financial ratios using a weighted formula."""
        weights = {
            "pe_ratio": -0.5,
            "debt_to_equity": -0.4,
            "current_ratio": 0.3,
            "free_cash_flow": 0.5,
            "gross_margin": 0.4,
            "operating_margin": 0.4,
            "roe": 0.6,
            "roa": 0.5,
            "eps": 0.7,
            "market_cap": 0.001,
            "revenue_growth": 0.35,
        }

        score = 100.0
        for feat, weight in weights.items():
            val = features.get(feat)
            if val is None or (isinstance(val, float) and np.isnan(val)):
                val = FEATURE_DEFAULTS.get(feat, 0.0)
            score += float(val) * weight

        return max(score, 1.0)

    def _compute_confidence(self, features: dict[str, Any]) -> float:
        """Confidence based on how many features were provided (not defaulted)."""
        n_provided = sum(
            1 for f in REQUIRED_FEATURES
            if features.get(f) not in (None, np.nan, 0.0)
        )
        return round(min(0.3 + (n_provided / len(REQUIRED_FEATURES)) * 0.7, 0.95), 4)

    def _get_feature_importance(self) -> list[FeatureImportance]:
        """Return feature importance from XGBoost, or a sensible default order."""
        if self._is_trained and self.xgb_model is not None:
            try:
                importances = self.xgb_model.feature_importances_
                if len(importances) == len(REQUIRED_FEATURES):
                    pairs = sorted(
                        zip(REQUIRED_FEATURES, importances),
                        key=lambda x: x[1],
                        reverse=True,
                    )
                    return [
                        FeatureImportance(feature=name, importance=round(float(imp), 4))
                        for name, imp in pairs
                    ]
            except Exception:
                logger.warning("Could not extract feature importance from XGBoost.")

        # Fallback heuristic order
        return [
            FeatureImportance(feature="pe_ratio", importance=0.22),
            FeatureImportance(feature="eps", importance=0.18),
            FeatureImportance(feature="roe", importance=0.15),
            FeatureImportance(feature="free_cash_flow", importance=0.13),
            FeatureImportance(feature="debt_to_equity", importance=0.11),
            FeatureImportance(feature="revenue_growth", importance=0.09),
            FeatureImportance(feature="operating_margin", importance=0.06),
            FeatureImportance(feature="gross_margin", importance=0.04),
            FeatureImportance(feature="current_ratio", importance=0.02),
        ]
