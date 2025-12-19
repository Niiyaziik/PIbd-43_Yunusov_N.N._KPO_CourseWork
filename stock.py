#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import sys
import os

if sys.platform == 'win32':
    try:
        import io
        if hasattr(sys.stdout, 'buffer') and not sys.stdout.buffer.closed:
            if not isinstance(sys.stdout, io.TextIOWrapper) or \
               (isinstance(sys.stdout, io.TextIOWrapper) and sys.stdout.encoding.lower() != 'utf-8'):
                try:
                    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
                except (ValueError, OSError):
                    pass
        
        if hasattr(sys.stderr, 'buffer') and not sys.stderr.buffer.closed:
            if not isinstance(sys.stderr, io.TextIOWrapper) or \
               (isinstance(sys.stderr, io.TextIOWrapper) and sys.stderr.encoding.lower() != 'utf-8'):
                try:
                    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')
                except (ValueError, OSError):
                    pass
    except (AttributeError, ValueError, OSError):
        pass

if len(sys.argv) < 2:
    print("Использование: python stock.py <TICKER>")
    sys.exit(1)

ticker = sys.argv[1].upper()

script_dir = os.path.dirname(os.path.abspath(__file__))
csv_dir = os.path.join(script_dir, 'storage', 'app', 'private', 'securities')
csv_dir = csv_dir.replace('\\', '/')
models_dir = os.path.join(script_dir, 'models')
models_dir = models_dir.replace('\\', '/')

missing_modules = []

try:
    import pandas as pd
except ImportError:
    missing_modules.append('pandas')

try:
    import numpy as np
except ImportError:
    missing_modules.append('numpy')

try:
    from sklearn.preprocessing import MinMaxScaler
    from sklearn.model_selection import train_test_split
    from sklearn.metrics import mean_squared_error, mean_absolute_error
except ImportError:
    missing_modules.append('scikit-learn')

try:
    import tensorflow as tf
    from tensorflow.keras.models import Sequential
    from tensorflow.keras.layers import LSTM, Dense, Dropout
    from tensorflow.keras.callbacks import EarlyStopping
    print("TensorFlow версия:", tf.__version__)
except ImportError:
    missing_modules.append('tensorflow')

if missing_modules:
    print(f"Ошибка: Отсутствуют необходимые модули: {', '.join(missing_modules)}")
    print(f"Python путь: {sys.executable}")
    print(f"Python версия: {sys.version}")
    print("\nРешение:")
    print(f"Установите недостающие модули:")
    print(f"  {sys.executable} -m pip install {' '.join(missing_modules)}")
    print("\nИли установите все сразу:")
    print(f"  {sys.executable} -m pip install pandas numpy scikit-learn tensorflow")
    sys.exit(1)

from datetime import datetime
import warnings
warnings.filterwarnings('ignore')

pd.set_option('display.max_columns', None)
pd.set_option('display.width', None)

print("Все библиотеки успешно импортированы!")

def load_ticker_data(ticker, csv_directory=csv_dir):
    file_path = os.path.join(csv_directory, f"{ticker.upper()}.csv")
    
    if not os.path.exists(file_path):
        print(f"Файл {file_path} не найден!")
        return None
    
    try:
        df = pd.read_csv(file_path)
        
        expected_columns = ['ticker', 'time', 'open', 'high', 'low', 'close', 'volume']
        
        df.columns = [col.strip() for col in df.columns]
        current_columns_lower = [col.lower() for col in df.columns]
        expected_columns_lower = [col.lower() for col in expected_columns]
        
        if set(current_columns_lower) == set(expected_columns_lower):
            column_mapping = {}
            for expected_col in expected_columns:
                for current_col in df.columns:
                    if current_col.lower() == expected_col.lower():
                        column_mapping[current_col] = expected_col
                        break
            
            df = df.rename(columns=column_mapping)
            df = df[expected_columns]
        else:
            if len(df.columns) == len(expected_columns):
                if len(df) > 0:
                    first_row_str = [str(val).strip().lower() for val in df.iloc[0].values]
                    if first_row_str == expected_columns_lower:
                        df = df.iloc[1:].reset_index(drop=True)
                        df.columns = expected_columns
        
        df['time'] = pd.to_datetime(df['time'], errors='coerce')
        
        df = df.dropna(subset=['time'])
        
        numeric_columns = ['open', 'high', 'low', 'close', 'volume']
        for col in numeric_columns:
            df[col] = pd.to_numeric(df[col], errors='coerce')
        
        df = df.dropna(subset=numeric_columns)
        
        df = df.sort_values('time').reset_index(drop=True)
        
        print(f"Загружено {len(df)} записей для тикера {ticker}")
        return df
        
    except Exception as e:
        print(f"Ошибка при загрузке данных: {e}")
        import traceback
        traceback.print_exc()
        return None

df = load_ticker_data(ticker)

if df is None or len(df) == 0:
    print(f"Не удалось загрузить данные для тикера {ticker}")
    sys.exit(1)

from sklearn.preprocessing import MinMaxScaler
import numpy as np
import os
import pickle


def prepare_pattern_data(df, lookback: int = 60, forecast_days: int = 1):
    df_close = df[['close']].dropna().reset_index(drop=True)

    if len(df_close) < lookback + forecast_days:
        print(f"Недостаточно данных для паттернов: нужно минимум {lookback + forecast_days}, есть {len(df_close)}")
        return None, None, None

    scaler = MinMaxScaler(feature_range=(0, 1))
    scaled = scaler.fit_transform(df_close.values)

    X, y = [], []
    for i in range(lookback, len(scaled) - forecast_days + 1):
        X.append(scaled[i - lookback:i])
        y.append(scaled[i + forecast_days - 1, 0])

    X = np.asarray(X)
    y = np.asarray(y)

    print(f"Создано {len(X)} паттернов, форма X: {X.shape}, форма y: {y.shape}")
    return X, y, scaler


lookback = 60
forecast_days = 1

X_pat, y_pat, scaler_pat = prepare_pattern_data(df, lookback=lookback, forecast_days=forecast_days)

if X_pat is None:
    print("Не удалось подготовить данные для обучения")
    sys.exit(1)

train_size = int(len(X_pat) * 0.8)
X_train_pat, X_test_pat = X_pat[:train_size], X_pat[train_size:]
y_train_pat, y_test_pat = y_pat[:train_size], y_pat[train_size:]

print(f"Обучающая выборка: {len(X_train_pat)} примеров")
print(f"Тестовая выборка: {len(X_test_pat)} примеров")

from tensorflow.keras.models import Sequential
from tensorflow.keras.layers import LSTM, Dense, Dropout
from tensorflow.keras.callbacks import EarlyStopping

model_pat = Sequential([
    LSTM(32, return_sequences=True, input_shape=(lookback, 1)),
    Dropout(0.3),
    LSTM(32, return_sequences=True),
    Dropout(0.3),
    LSTM(32),
    Dropout(0.3),
    Dense(1),
])

model_pat.compile(optimizer='adam', loss='mse', metrics=['mae'])

print("Начало обучения модели...")
early_stopping_pat = EarlyStopping(
    monitor='val_loss',
    patience=10,
    restore_best_weights=True
)

history_pat = model_pat.fit(
    X_train_pat, y_train_pat,
    batch_size=32,
    epochs=80,
    validation_split=0.2,
    callbacks=[early_stopping_pat],
    verbose=1
)

train_pred_pat = model_pat.predict(X_train_pat)
test_pred_pat = model_pat.predict(X_test_pat)

train_pred_pat_actual = scaler_pat.inverse_transform(train_pred_pat.reshape(-1, 1))
test_pred_pat_actual = scaler_pat.inverse_transform(test_pred_pat.reshape(-1, 1))
y_train_pat_actual = scaler_pat.inverse_transform(y_train_pat.reshape(-1, 1))
y_test_pat_actual = scaler_pat.inverse_transform(y_test_pat.reshape(-1, 1))

train_mse_pat = mean_squared_error(y_train_pat_actual, train_pred_pat_actual)
test_mse_pat = mean_squared_error(y_test_pat_actual, test_pred_pat_actual)
train_mae_pat = mean_absolute_error(y_train_pat_actual, train_pred_pat_actual)
test_mae_pat = mean_absolute_error(y_test_pat_actual, test_pred_pat_actual)

accuracy = 0.0
mape = None
mask = y_test_pat_actual.flatten() != 0
if mask.any():
    mape = np.mean(np.abs((y_test_pat_actual.flatten()[mask] - test_pred_pat_actual.flatten()[mask]) / y_test_pat_actual.flatten()[mask])) * 100
    accuracy = max(0, min(100, 100 - mape))
else:
    mape = np.nan
    accuracy = 0.0

print(f"\nМетрики модели на паттернах:")
print(f"Обучающая выборка - MSE: {train_mse_pat:.4f}, MAE: {train_mae_pat:.4f}")
print(f"Тестовая выборка - MSE: {test_mse_pat:.4f}, MAE: {test_mae_pat:.4f}")
if not np.isnan(mape):
    print(f"MAPE: {mape:.2f}%, Точность: {accuracy:.2f}%")
else:
    print(f"MAPE: невозможно вычислить, Точность: {accuracy:.2f}%")

os.makedirs(models_dir, exist_ok=True)
model_path_pat = os.path.join(models_dir, f'lstm_patterns_{ticker.lower()}.h5')
scaler_path_pat = os.path.join(models_dir, f'scaler_patterns_{ticker.lower()}.pkl')

try:
    model_pat.save(model_path_pat, save_format='h5')
except:
    model_pat.save(model_path_pat)
with open(scaler_path_pat, 'wb') as f:
    pickle.dump(scaler_pat, f)

data_snapshot_path = os.path.join(models_dir, f'data_snapshot_{ticker.lower()}.pkl')
data_snapshot = {
    'df': df.copy(),
    'X_pat': X_pat,
    'last_sequence': X_pat[-1],
    'last_date': str(df['time'].iloc[-1]),
    'last_price': float(df['close'].iloc[-1]),
    'lookback': lookback,
    'forecast_days': forecast_days,
    'timestamp': pd.Timestamp.now().isoformat(),
    'accuracy': float(accuracy),
    'mape': float(mape) if (mape is not None and not np.isnan(mape)) else None,
    'test_mae': float(test_mae_pat)
}
try:
    with open(data_snapshot_path, 'wb') as f:
        pickle.dump(data_snapshot, f)
    print(f"\nМодель сохранена: {model_path_pat}")
    print(f"Scaler сохранен: {scaler_path_pat}")
    print(f"Снимок данных сохранен: {data_snapshot_path}")
    print(f"Точность модели: {accuracy:.2f}%")
    print(f"Обучение модели для {ticker} завершено успешно!")
except Exception as e:
    print(f"\nОШИБКА при сохранении снимка данных: {str(e)}")
    print(f"Модель сохранена: {model_path_pat}")
    print(f"Scaler сохранен: {scaler_path_pat}")
    import traceback
    traceback.print_exc()

print("\n" + "="*60)
print("ГЕНЕРАЦИЯ ПРОГНОЗА НА 252 ДНЯ ВПЕРЕД")
print("="*60)

if df is not None and X_pat is not None:
    last_seq = X_pat[-1]
    current_seq = last_seq.copy()
    
    n_days = 252
    current_price = float(df['close'].iloc[-1])
    future_price = current_price
    
    print(f"Текущая цена: {current_price:.2f} руб.")
    print(f"Генерация прогноза на {n_days} дней...")
    
    for day in range(n_days):
        next_scaled = model_pat.predict(current_seq.reshape(1, current_seq.shape[0], current_seq.shape[1]), verbose=0)
        
        next_price = scaler_pat.inverse_transform(next_scaled.reshape(-1, 1))[0, 0]
        future_price = float(next_price)
        
        new_row = np.array([[next_scaled[0, 0]]])
        current_seq = np.vstack([current_seq[1:], new_row])
        
        if (day + 1) % 50 == 0:
            print(f"  Прогресс: {day + 1}/{n_days} дней, текущий прогноз: {future_price:.2f} руб.")
    
    print("\n" + "-"*60)
    print(f"РЕЗУЛЬТАТ ПРОГНОЗА:")
    print(f"  Текущая цена: {current_price:.2f} руб.")
    print(f"  Прогнозируемая цена через {n_days} дней: {future_price:.2f} руб.")
    print(f"  Изменение: {future_price - current_price:.2f} руб. ({((future_price / current_price - 1) * 100):+.2f}%)")
    print("-"*60)
else:
    print("Не удалось сгенерировать прогноз: отсутствуют необходимые данные")

