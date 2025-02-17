<?php
namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Service;
use Midtrans\Snap;
use Midtrans\Notification;
use Midtrans\Config;
use Illuminate\Support\Facades\Log;
use illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;


class OrderController extends Controller
{
    public function index()
    {
        $services = Service::all();
        $user = auth()->user();
        return view('customer.order.index', compact('services', 'user'));
    }

    // public function store(Request $request)
    // {
    //     $cart = json_decode($request->input('cart'), true);

    //     try {
    //         // Perform order creation logic here
    //         $taxRate = 0.10; // Tax rate 10%
    //         $order = new Order();
    //         $order->user_id = auth()->id();
    //         $subtotal = array_reduce($cart, function ($sum, $item) {
    //             return $sum + ($item['price'] * $item['qty']);
    //         }, 0);
    //         $taxAmount = $subtotal * $taxRate;
    //         $order->total_price = $subtotal + $taxAmount;
    //         $order->save();

    //         // Save each item in the cart to the database
    //         foreach ($cart as $item) {
    //             $order->items()->create([
    //                 'service_id' => $item['id'],
    //                 'quantity' => $item['qty'],
    //                 'price' => $item['price'],
    //             ]);
    //         }

    //         return redirect()->route('customer.orders')->with('success', 'Order placed successfully!');
    //     } catch (\Exception $e) {
    //         return redirect()->route('customer.orders')->with('error', 'An error occurred while placing the order.');
    //     }

    // }
    public function store(Request $request)
    {
        $cart = json_decode($request->input('cart'), true);

        try {
            $taxRate = 0.10; // Tax rate 10%
            $order = new Order();
            $order->user_id = auth()->id();
            $subtotal= array_reduce($cart, function ($sum, $item) {
                return $sum + ($item['price'] * $item['qty']);
            }, 0);

            $taxAmount = $subtotal * $taxRate;
            $order->total_price = $subtotal + $taxAmount;
            $order->save();

            foreach ($cart as $item) {
                $order->items()->create([
                    'service_id' => $item['id'],
                    'quantity' => $item['qty'],
                    'price' => $item['price'],
                ]);
            }

            return redirect()->route('customer.orders')->with('success', 'Order placed successfully!');
        } catch (\Exception $e) {
            return redirect()->route('customer.orders')->with('error', 'An error occurred while placing the order.');
        }
    }


    public function invoice(Order $order)
    {
        return view('customer.order.invoice', compact('order'));
    }


    public function pay(Order $order)
    {
        // Hitung total harga termasuk pajak
        $taxRate = 0.10; // 10%
        $totalPrice = $order->items->sum(function ($item) use ($taxRate) {
            $taxAmount = $item->price * $taxRate;
            return ($item->price + $taxAmount) * $item->quantity;
        });

        // DD($totalPrice);

        // Konfigurasi Midtrans
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        // Buat detail transaksi
        $params = [
            'transaction_details' => [
                'order_id' => 'TRXX' . $order->id,
                'gross_amount' => $totalPrice,
            ],
            'customer_details' => [
                'first_name' => Auth::user()->name,
                'email' => Auth::user()->email,
            ],
            'item_details' => $order->items->map(function ($item) use ($taxRate) {
                $taxAmount = $item->price * $taxRate;
                $totalItemPrice = $item->price + $taxAmount;
                return [
                    'id' => $item->id,
                    'price' => $totalItemPrice,
                    'quantity' => $item->quantity,
                    'name' => $item->service->name_service,
                ];
            })->toArray(),
        ];

        // Buat token Snap
        $snapToken = Snap::getSnapToken($params);

        // Tampilkan halaman pembayaran
        return view('customer.payment.index', compact('order', 'snapToken', 'totalPrice'));
    }

    public function notificationHandler(Request $request)
    {
        Log::info('Midtrans notification received', ['payload' => $request->all()]);

        try {
            // Konfigurasi Midtrans
            Config::$serverKey = config('midtrans.server_key');
            Config::$isProduction = config('midtrans.is_production');
            Config::$isSanitized = config('midtrans.sanitized');
            Config::$is3ds = config('midtrans.3ds');

            $notif = new Notification();

            Log::info('Midtrans Notification Object', ['notification' => $notif]);

            $transactionStatus = $notif->transaction_status;
            $orderId = $notif->order_id;

            // Remove "TRX" prefix to get the actual order ID
            $orderId = str_replace('TRXX', '', $orderId);

            Log::info('Midtrans Notification Details', [
                'transaction_status' => $transactionStatus,
                'order_id' => $orderId,
            ]);

            // Find the order using the actual order ID
            $order = Order::findOrFail($orderId);

            if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
                $order->status = 'paid';
                // Generate tracking number
                $order->tracking_number = 'TRK' . strtoupper(Str::random(8));
            } elseif ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
                $order->status = 'failed';
            } elseif ($transactionStatus == 'pending') {
                $order->status = 'pending';
            }

            $order->save();

            Log::info('Order status updated', ['order' => $order]);

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Midtrans Notification Error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Internal Server Error'], 500);
        }
    }

public function history()
{
    $user = auth()->user();
    $orders = Order::where('user_id', $user->id)->with('items.service')->get();
    $completedOrders = $orders->where('status_order', 'completed')->whereNull('rating');

    return view('customer.order.history', compact('orders', 'completedOrders', 'user'));
}

public function updateRating(Request $request, Order $order)
{
    $request->validate([
        'rating' => 'required|integer|min:1|max:5',
        'review' => 'required|string|max:255',
    ]);

    $order->update([
        'rating' => $request->rating,
        'review' => $request->review,
    ]);

    return redirect()->route('customer.orders.history')->with('success', 'Thank you for your feedback!');
}






}
