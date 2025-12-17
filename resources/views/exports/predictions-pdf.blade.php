<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Предсказания по ценной бумаге {{ $ticker }}</title>
    <style>
        body {
            /* DejaVu Sans включён в DomPDF и поддерживает кириллицу */
            font-family: 'DejaVu Sans', sans-serif;
            margin: 20px;
            color: #333;
        }
        h1 {
            color: #2563eb;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #2563eb;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .recommendation-buy {
            color: #10b981;
            font-weight: bold;
        }
        .recommendation-sell {
            color: #ef4444;
            font-weight: bold;
        }
        .recommendation-hold {
            color: #f59e0b;
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <h1>Предсказания по ценной бумаге {{ $ticker }}</h1>
    <p><strong>Дата формирования отчета:</strong> {{ $date }}</p>
    
    <table>
        <thead>
            <tr>
                <th>Ценная бумага</th>
                <th>Текущая цена</th>
                <th>Прогноз на 1 день</th>
                <th>Прогноз на 252 дня</th>
                <th>Рекомендация</th>
            </tr>
        </thead>
        <tbody>
            @foreach($predictions as $prediction)
                @php
                    $price1d = $prediction['predicted_price_1d'] ?? ($prediction['predicted_price'] ?? 0);
                    $price252d = $prediction['predicted_price_252d'] ?? ($prediction['predicted_price'] ?? 0);
                @endphp
                <tr>
                    <td>{{ $prediction['ticker'] }}</td>
                    <td>{{ number_format($prediction['current_price'], 2, ',', ' ') }} руб.</td>
                    <td>{{ number_format($price1d, 2, ',', ' ') }} руб.</td>
                    <td>{{ number_format($price252d, 2, ',', ' ') }} руб.</td>
                    <td class="recommendation-{{ 
                        $prediction['recommendation'] === 'Покупать' ? 'buy' : 
                        ($prediction['recommendation'] === 'Не покупать' ? 'sell' : 'hold') 
                    }}">
                        {{ $prediction['recommendation'] }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    
    <div class="footer">
        <p>Информация предоставляется исключительно в информационных целях и не является инвестиционной рекомендацией.</p>
    </div>
</body>
</html>

