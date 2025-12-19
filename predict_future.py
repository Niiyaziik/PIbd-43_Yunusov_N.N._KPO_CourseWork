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

# Подавляем информационные сообщения TensorFlow
import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'  # 0 = все, 1 = INFO, 2 = WARNING, 3 = ERROR

if len(sys.argv) < 2:
    print(json.dumps({'error': 'Не указан тикер'}))
    sys.exit(1)

ticker = sys.argv[1].upper()
days = int(sys.argv[2]) if len(sys.argv) > 2 else 252  # По умолчанию 252 дня (год)

# Опция использования снимка данных (по умолчанию True - используем снимок если есть)
use_snapshot = True
if len(sys.argv) > 3:
    use_snapshot_arg = sys.argv[3].lower()
    use_snapshot = use_snapshot_arg in ['1', 'true', 'yes', 'snapshot']

script_dir = os.path.dirname(os.path.abspath(__file__))
models_dir = os.path.join(script_dir, 'models')
csv_dir = os.path.join(script_dir, 'storage', 'app', 'private', 'securities')

# Проверяем наличие модели
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
    # Импортируем TensorFlow с подавлением вывода
    import os
    os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'
    
    import pandas as pd
    import numpy as np
    import pickle
    from tensorflow.keras.models import load_model
    from sklearn.preprocessing import MinMaxScaler
    
    # Перенаправляем stderr TensorFlow в /dev/null (или nul на Windows)
    import warnings
    warnings.filterwarnings('ignore')
except ImportError as e:
    print(json.dumps({'error': f'Ошибка импорта модулей: {str(e)}'}))
    sys.exit(1)

try:
    # Подавляем вывод TensorFlow при загрузке модели
    import warnings
    with warnings.catch_warnings():
        warnings.simplefilter("ignore")
        # Загружаем модель без компиляции (чтобы избежать ошибок совместимости версий)
        # compile=False позволяет загрузить модель без перекомпиляции метрик
        try:
            model = load_model(model_path, compile=False)
        except Exception as e:
            # Если не удалось загрузить без компиляции, пробуем с компиляцией
            try:
                model = load_model(model_path, compile=True)
            except Exception as e2:
                # Улучшенное сообщение об ошибке
                error_msg = str(e2)
                if 'deserialize' in error_msg or 'KerasSaveable' in error_msg:
                    error_msg = "Ошибка совместимости версий TensorFlow. Модель была сохранена с другой версией. Попробуйте переобучить модель."
                print(json.dumps({
                    'error': f'Ошибка при загрузке модели: {error_msg}'
                }))
                sys.exit(1)
    
    with open(scaler_path, 'rb') as f:
        scaler = pickle.load(f)
    
    # Пробуем использовать снимок данных, если доступен и опция включена
    # ВАЖНО: Проверяем снимок ДО загрузки CSV, чтобы использовать его если доступен
    data_snapshot = None
    snapshot_available = os.path.exists(data_snapshot_path)
    
    # Выводим информацию о доступности снимка (это будет видно в JSON ответе, так как идет в stdout перед основным JSON)
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
        # Используем сохраненный снимок данных
        # ВАЖНО: Используем именно те данные, которые были при обучении
        df = data_snapshot['df'].copy()
        X_pat = data_snapshot['X_pat']
        last_sequence = data_snapshot['last_sequence'].copy()  # Явно копируем для безопасности
        last_date_raw = data_snapshot['last_date']
        # Преобразуем дату в строку, если это Timestamp или другой объект
        if hasattr(last_date_raw, 'strftime'):
            # Это datetime или Timestamp объект
            try:
                last_date_str = last_date_raw.strftime('%Y-%m-%d %H:%M:%S')
            except:
                last_date_str = str(last_date_raw)
        elif hasattr(last_date_raw, 'to_pydatetime'):
            # Это pandas Timestamp
            try:
                last_date_str = last_date_raw.to_pydatetime().strftime('%Y-%m-%d %H:%M:%S')
            except:
                last_date_str = str(last_date_raw)
        else:
            last_date_str = str(last_date_raw)
        
        current_price = data_snapshot['last_price']
        lookback = data_snapshot['lookback']
        forecast_days = data_snapshot['forecast_days']
        
        # Проверяем, что last_sequence соответствует X_pat[-1]
        # Это должно быть одинаково, но проверка не помешает
        if not np.array_equal(last_sequence, X_pat[-1]):
            print(json.dumps({'warning': 'last_sequence из снимка не соответствует X_pat[-1]. Используется last_sequence из снимка.'}), file=sys.stderr)
        
        # Выводим детальную информацию о снимке в stdout (чтобы было видно в JSON)
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
        # Загружаем данные из CSV (как раньше)
        csv_path = os.path.join(csv_dir, f'{ticker}.csv')
        if not os.path.exists(csv_path):
            print(json.dumps({'error': f'CSV файл не найден: {csv_path}'}))
            sys.exit(1)
        
        df = pd.read_csv(csv_path)
    
        # Убеждаемся, что колонки правильные
        df.columns = [col.strip() for col in df.columns]
        if 'time' not in df.columns or 'close' not in df.columns:
            # Пропускаем заголовок если он есть
            if len(df) > 0 and str(df.iloc[0, 0]).lower() == 'ticker':
                df = df.iloc[1:].reset_index(drop=True)
                df.columns = ['ticker', 'time', 'open', 'high', 'low', 'close', 'volume']
        
        # Важно: удаляем прогнозные данные (будущие даты) из CSV
        # чтобы использовать только реальные исторические данные
        # Также удаляем прогнозы, которые были добавлены ранее
        try:
            df['time_parsed'] = pd.to_datetime(df['time'])
            now = datetime.now()
            # Оставляем только данные до текущей даты (реальные исторические данные)
            # Это гарантирует, что мы используем те же данные, что были доступны при обучении
            df_historical = df[df['time_parsed'] <= now].copy()
            
            # Сортируем по дате и берем только уникальные даты (на случай дубликатов)
            df_historical = df_historical.sort_values('time_parsed').drop_duplicates(subset='time_parsed', keep='last')
            df = df_historical.drop('time_parsed', axis=1).reset_index(drop=True)
        except Exception as e:
            # Если не удалось распарсить даты, просто продолжаем с исходными данными
            pass
        
        # Важно: убедимся, что данные отсортированы по дате (от старых к новым)
        try:
            df['time_sort'] = pd.to_datetime(df['time'])
            df = df.sort_values('time_sort').drop('time_sort', axis=1).reset_index(drop=True)
        except:
            pass
        
        # Подготавливаем данные точно так же, как в stock.py/stock.ipynb
        # Используем функцию prepare_pattern_data для согласованности
        def prepare_pattern_data_local(df, lookback: int = 60, forecast_days: int = 1, scaler_to_use=None):
            """Подготовка данных для LSTM - та же логика, что в stock.py"""
            df_close = df[['close']].dropna().reset_index(drop=True)
            
            if len(df_close) < lookback + forecast_days:
                return None, None
            
            # Используем тот же scaler, что был при обучении (уже загружен)
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
        
        # Подготавливаем паттерны (как при обучении), используя загруженный scaler
        X_pat, y_pat = prepare_pattern_data_local(df, lookback=lookback, forecast_days=forecast_days, scaler_to_use=scaler)
        
        if X_pat is None or len(X_pat) == 0:
            print(json.dumps({'error': f'Недостаточно данных: нужно минимум {lookback + forecast_days} записей для создания паттернов'}))
            sys.exit(1)
        
        # Берем последний паттерн из подготовленных данных (как в stock.ipynb)
        last_sequence = X_pat[-1]  # shape: (lookback, 1) - уже правильная форма
        
        # Получаем последнюю дату и цену
        last_date_str = df['time'].iloc[-1]
        current_price = float(df['close'].iloc[-1])
    
    # Общая часть для обоих случаев (снимок или CSV)
    # Генерируем прогнозы на будущие дни (точно так же, как в stock.ipynb)
    predictions = []
    current_seq = last_sequence.copy()  # shape: (lookback, 1)
    
    # Парсим последнюю дату
    # ВАЖНО: Преобразуем last_date_str в строку, если это Timestamp или другой объект
    last_date_str = str(last_date_str)
    
    try:
        try:
            # Пробуем через pandas, но преобразуем в обычный datetime
            pd_date = pd.to_datetime(last_date_str)
            # Преобразуем pandas Timestamp в обычный datetime
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
    
    # current_price уже установлен выше (из снимка или из CSV)
    future_price = current_price
    
    # Подавляем предупреждения при прогнозировании
    warnings.filterwarnings('ignore')

    first_prediction = None
    
    for i in range(days):
        # Предсказываем следующее значение (формат такой же, как в stock.ipynb)
        # verbose=0 подавляет вывод прогресса
        next_scaled = model.predict(current_seq.reshape(1, current_seq.shape[0], current_seq.shape[1]), verbose=0)
        
        # Денормализуем в реальную цену
        next_price = scaler.inverse_transform(next_scaled.reshape(-1, 1))[0, 0]
        future_price = float(next_price)
        
        # Для CSV вычисляем дату (пропускаем выходные для реалистичности)
        # Но для подсчета дней до 252-го прогноза это не влияет
        if i == 0:
            current_date = last_date
        
        current_date = current_date + timedelta(days=1)
        # Если выходной, сдвигаем на следующий рабочий день
        while current_date.weekday() >= 5:  # 5 = суббота, 6 = воскресенье
            current_date += timedelta(days=1)
        
        # Используем прогнозную цену для всех полей (open, high, low, close)
        # volume можно оставить 0 или использовать средний объем
        avg_volume = int(df['volume'].tail(30).mean()) if 'volume' in df.columns else 0
        
        predictions.append({
            'time': current_date.strftime('%Y-%m-%d %H:%M:%S'),
            'open': float(next_price),
            'high': float(next_price * 1.02),  # Небольшое отклонение
            'low': float(next_price * 0.98),
            'close': float(next_price),
            'volume': avg_volume
        })
        
        # Обновляем окно паттерна точно так же, как в stock.ipynb
        # Используем np.vstack как в оригинале
        new_row = np.array([[next_scaled[0, 0]]])  # shape: (1, 1)
        current_seq = np.vstack([current_seq[1:], new_row])
    
    # Получаем точность модели из снимка данных
    model_accuracy = None
    if data_snapshot and 'accuracy' in data_snapshot:
        model_accuracy = float(data_snapshot['accuracy'])
    
    # Возвращаем JSON
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
        # Важная информация о том, откуда взялись данные
        'used_snapshot': data_snapshot is not None,  # Информация о том, использовался ли снимок
        'snapshot_timestamp': str(data_snapshot.get('timestamp')) if (data_snapshot and data_snapshot.get('timestamp')) else None,
        'data_source': 'snapshot' if data_snapshot is not None else 'csv',  # Откуда взялись данные
        'model_accuracy': model_accuracy,  # Точность модели в процентах
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

