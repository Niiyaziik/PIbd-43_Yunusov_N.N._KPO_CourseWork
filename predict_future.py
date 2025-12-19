#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import sys
import os
import json
from datetime import datetime, timedelta

if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'

if len(sys.argv) < 2:
    print(json.dumps({'error': 'Не указан тикер'}))
    sys.exit(1)

ticker = sys.argv[1].upper()
days = int(sys.argv[2]) if len(sys.argv) > 2 else 252

use_snapshot = True
if len(sys.argv) > 3:
    use_snapshot_arg = sys.argv[3].lower()
    use_snapshot = use_snapshot_arg in ['1', 'true', 'yes', 'snapshot']

script_dir = os.path.dirname(os.path.abspath(__file__))
models_dir = os.path.join(script_dir, 'models')
csv_dir = os.path.join(script_dir, 'storage', 'app', 'private', 'securities')

model_path = os.path.join(models_dir, f'lstm_patterns_{ticker.lower()}.h5')
scaler_path = os.path.join(models_dir, f'scaler_patterns_{ticker.lower()}.pkl')
data_snapshot_path = os.path.join(models_dir, f'data_snapshot_{ticker.lower()}.pkl')

if not os.path.exists(model_path) or not os.path.exists(scaler_path):
    print(json.dumps({
        'error': f'Модель для тикера {ticker} не найдена. Сначала обучите модель.',
        'model_exists': os.path.exists(model_path),
        'scaler_exists': os.path.exists(scaler_path)
    }))
    sys.exit(1)

try:
    import os
    os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'
    
    import pandas as pd
    import numpy as np
    import pickle
    from tensorflow.keras.models import load_model
    from sklearn.preprocessing import MinMaxScaler
    
    import warnings
    warnings.filterwarnings('ignore')
except ImportError as e:
    print(json.dumps({'error': f'Ошибка импорта модулей: {str(e)}'}))
    sys.exit(1)

try:
    import warnings
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        try:
            model = load_model(model_path, compile=False)
        except Exception as e:
            try:
                model = load_model(model_path, compile=True)
            except Exception as e2:
                error_msg = str(e2)
                if 'deserialize' in error_msg or 'KerasSaveable' in error_msg:
                    error_msg = "Ошибка совместимости версий TensorFlow. Модель была сохранена с другой версией. Попробуйте переобучить модель."
                print(json.dumps({
                    'error': f'Ошибка при загрузке модели: {error_msg}'
                }))
                sys.exit(1)
    
    with open(scaler_path, 'rb') as f:
        scaler = pickle.load(f)
    
    data_snapshot = None
    snapshot_available = os.path.exists(data_snapshot_path)
    print(json.dumps({
        'debug': {
            'snapshot_requested': use_snapshot,
            'snapshot_exists': snapshot_available,
            'snapshot_path': data_snapshot_path
        }
    }), file=sys.stderr)
    
    if use_snapshot and snapshot_available:
        try:
            with open(data_snapshot_path, 'rb') as f:
                data_snapshot = pickle.load(f)
            print(json.dumps({'info': f'Используется снимок данных от {data_snapshot.get("timestamp", "unknown")}'}), file=sys.stderr)
        except Exception as e:
            print(json.dumps({'warning': f'Не удалось загрузить снимок данных: {str(e)}. Используется CSV.'}), file=sys.stderr)
            data_snapshot = None
    elif use_snapshot and not snapshot_available:
        print(json.dumps({'warning': f'Снимок данных не найден по пути: {data_snapshot_path}. Используется CSV.'}), file=sys.stderr)
    
    if data_snapshot:
        df = data_snapshot['df'].copy()
        X_pat = data_snapshot['X_pat']
        last_sequence = data_snapshot['last_sequence'].copy()
        last_date_raw = data_snapshot['last_date']
        if hasattr(last_date_raw, 'strftime'):
            try:
                last_date_str = last_date_raw.strftime('%Y-%m-%d %H:%M:%S')
            except:
                last_date_str = str(last_date_raw)
        elif hasattr(last_date_raw, 'to_pydatetime'):
            try:
                last_date_str = last_date_raw.to_pydatetime().strftime('%Y-%m-%d %H:%M:%S')
            except:
                last_date_str = str(last_date_raw)
        else:
            last_date_str = str(last_date_raw)
        
        current_price = data_snapshot['last_price']
        lookback = data_snapshot['lookback']
        forecast_days = data_snapshot['forecast_days']
        
        if not np.array_equal(last_sequence, X_pat[-1]):
            print(json.dumps({'warning': 'last_sequence из снимка не соответствует X_pat[-1]. Используется last_sequence из снимка.'}), file=sys.stderr)
        snapshot_info = {
            'snapshot_used': True,
            'snapshot_timestamp': str(data_snapshot.get('timestamp')) if data_snapshot.get('timestamp') else None,
            'records_count': len(df),
            'last_date': str(last_date_str),
            'last_price': float(current_price),
            'last_sequence_shape': list(last_sequence.shape),
            'lookback': int(lookback),
            'forecast_days': int(forecast_days)
        }
        print(json.dumps({'debug_info': snapshot_info}), file=sys.stderr)
        
        print(json.dumps({'info': f'Снимок данных: {len(df)} записей, последняя дата: {last_date_str}, последняя цена: {current_price:.2f}, размер last_sequence: {last_sequence.shape}'}), file=sys.stderr)
    else:
        csv_path = os.path.join(csv_dir, f'{ticker}.csv')
        if not os.path.exists(csv_path):
            print(json.dumps({'error': f'CSV файл не найден: {csv_path}'}))
            sys.exit(1)
        
        df = pd.read_csv(csv_path)
    
        df.columns = [col.strip() for col in df.columns]
        if 'time' not in df.columns or 'close' not in df.columns:
            if len(df) > 0 and str(df.iloc[0, 0]).lower() == 'ticker':
                df = df.iloc[1:].reset_index(drop=True)
                df.columns = ['ticker', 'time', 'open', 'high', 'low', 'close', 'volume']
        
        try:
            df['time_parsed'] = pd.to_datetime(df['time'])
            now = datetime.now()
            df_historical = df[df['time_parsed'] <= now].copy()
            
            df_historical = df_historical.sort_values('time_parsed').drop_duplicates(subset='time_parsed', keep='last')
            df = df_historical.drop('time_parsed', axis=1).reset_index(drop=True)
        except Exception as e:
            pass
        
        try:
            df['time_sort'] = pd.to_datetime(df['time'])
            df = df.sort_values('time_sort').drop('time_sort', axis=1).reset_index(drop=True)
        except:
            pass
        
        def prepare_pattern_data_local(df, lookback: int = 60, forecast_days: int = 1, scaler_to_use=None):
            """Подготовка данных для LSTM - та же логика, что в stock.py"""
            df_close = df[['close']].dropna().reset_index(drop=True)
            
            if len(df_close) < lookback + forecast_days:
                return None, None
            
            scaled = scaler_to_use.transform(df_close.values)
            
            X, y = [], []
            for i in range(lookback, len(scaled) - forecast_days + 1):
                X.append(scaled[i - lookback:i])
                y.append(scaled[i + forecast_days - 1, 0])
            
            X = np.asarray(X)
            y = np.asarray(y)
            
            return X, y
        
        lookback = 60
        forecast_days = 1
        
        X_pat, y_pat = prepare_pattern_data_local(df, lookback=lookback, forecast_days=forecast_days, scaler_to_use=scaler)
        
        if X_pat is None or len(X_pat) == 0:
            print(json.dumps({'error': f'Недостаточно данных: нужно минимум {lookback + forecast_days} записей для создания паттернов'}))
            sys.exit(1)
        
        last_sequence = X_pat[-1]
        
        last_date_str = df['time'].iloc[-1]
        current_price = float(df['close'].iloc[-1])
    
    predictions = []
    current_seq = last_sequence.copy()
    
    last_date_str = str(last_date_str)
    
    try:
        try:
            pd_date = pd.to_datetime(last_date_str)
            if hasattr(pd_date, 'to_pydatetime'):
                last_date = pd_date.to_pydatetime()
            elif hasattr(pd_date, 'to_datetime64'):
                last_date = pd_date.to_pydatetime()
            else:
                last_date = datetime.strptime(str(pd_date), '%Y-%m-%d %H:%M:%S')
        except:
            try:
                last_date = datetime.strptime(last_date_str, '%Y-%m-%d')
            except:
                try:
                    last_date = datetime.strptime(last_date_str, '%Y-%m-%d %H:%M:%S')
                except:
                    last_date = datetime.now()
    except Exception as e:
        print(json.dumps({'error': f'Ошибка парсинга даты: {str(e)}'}), file=sys.stderr)
        last_date = datetime.now()
    
    future_price = current_price
    
    warnings.filterwarnings('ignore')
    
    first_prediction = None
    
    for i in range(days):
        next_scaled = model.predict(current_seq.reshape(1, current_seq.shape[0], current_seq.shape[1]), verbose=0)
        
        next_price = scaler.inverse_transform(next_scaled.reshape(-1, 1))[0, 0]
        future_price = float(next_price)
        
        if i == 0:
            current_date = last_date
        
        current_date = current_date + timedelta(days=1)
        while current_date.weekday() >= 5:
            current_date += timedelta(days=1)
        
        avg_volume = int(df['volume'].tail(30).mean()) if 'volume' in df.columns else 0
        
        predictions.append({
            'time': current_date.strftime('%Y-%m-%d %H:%M:%S'),
            'open': float(next_price),
            'high': float(next_price * 1.02),  # Небольшое отклонение
            'low': float(next_price * 0.98),
            'close': float(next_price),
            'volume': avg_volume
        })
        
        new_row = np.array([[next_scaled[0, 0]]])
        current_seq = np.vstack([current_seq[1:], new_row])
    
    model_accuracy = None
    if data_snapshot and 'accuracy' in data_snapshot:
        model_accuracy = float(data_snapshot['accuracy'])
    
    result = {
        'ticker': ticker,
        'current_price': current_price,
        'predicted_price_252d': future_price,  # Цена через 252 дня (последний прогноз)
        'change_252d': future_price - current_price,
        'change_252d_percent': ((future_price / current_price - 1) * 100) if current_price > 0 else 0,
        'first_prediction': first_prediction,  # Отладочная информация о первом прогнозе
        'predictions': predictions,
        'count': len(predictions),
        'last_historical_date': last_date_str,
        'first_prediction_date': predictions[0]['time'] if predictions else None,
        'last_prediction_date': predictions[-1]['time'] if predictions else None,
        'used_snapshot': data_snapshot is not None,
        'snapshot_timestamp': str(data_snapshot.get('timestamp')) if (data_snapshot and data_snapshot.get('timestamp')) else None,
        'data_source': 'snapshot' if data_snapshot is not None else 'csv',
        'model_accuracy': model_accuracy,
        'snapshot_info': {
            'exists': snapshot_available,
            'requested': use_snapshot
        } if data_snapshot is None else None
    }
    
    print(json.dumps(result, ensure_ascii=False, indent=2))
    
except Exception as e:
    print(json.dumps({
        'error': f'Ошибка при генерации прогнозов: {str(e)}',
        'traceback': str(e.__class__.__name__)
    }))
    import traceback
    traceback.print_exc()
    sys.exit(1)

