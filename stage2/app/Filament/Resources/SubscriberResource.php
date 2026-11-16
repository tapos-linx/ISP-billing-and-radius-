<?php
// File: app/Filament/Resources/SubscriberResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SubscriberStatus;
use App\Filament\Resources\SubscriberResource\Pages;
use App\Models\Subscriber;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'BSS Subscriber Management';
    protected static ?string $navigationLabel = 'Subscribers Directory';
    protected static ?string $recordTitleAttribute = 'username';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('PPPoE AAA Credentials & Citus Shard')
                    ->description('Saving this form automatically triggers SubscriberObserver -> RadiusSyncService inside a PostgreSQL transaction.')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('PPPoE Username')
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Forms\Set $set): void {
                                if ($state !== null && $state !== '') {
                                    $set('cgnat_shard_id', Subscriber::computeShardId($state));
                                }
                            }),

                        Forms\Components\TextInput::make('pppoe_password')
                            ->label('Cleartext Password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->maxLength(128),

                        Forms\Components\TextInput::make('cgnat_shard_id')
                            ->label('Citus Shard ID (0..127)')
                            ->numeric()
                            ->disabled()
                            ->dehydrated()
                            ->default(0),
                    ]),

                Forms\Components\Section::make('Network Access Server (NAS), Tariff & Status')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('nas_id')
                            ->label('Linked NAS / BNG Router')
                            ->relationship('nas', 'shortname')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('tariff_id')
                            ->label('Linked Tariff Package')
                            ->relationship('tariff', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('status')
                            ->label('Account Status')
                            ->options(SubscriberStatus::class)
                            ->default(SubscriberStatus::ACTIVE)
                            ->enum(SubscriberStatus::class)
                            ->required(),

                        Forms\Components\TextInput::make('static_ipv4')
                            ->label('Assigned IPv4 Address (Framed-IP-Address)')
                            ->ipv4()
                            ->placeholder('Dynamic from Tariff Pool if empty')
                            ->nullable(),

                        Forms\Components\DateTimePicker::make('expires_at')
                            ->label('Subscription Expiry Date')
                            ->required()
                            ->default(now()->addDays(30)),

                        Forms\Components\Select::make('pop_division')
                            ->label('POP Division')
                            ->options([
                                'Dhaka'      => 'Dhaka',
                                'Chittagong' => 'Chittagong',
                                'Rajshahi'   => 'Rajshahi',
                                'Khulna'     => 'Khulna',
                                'Sylhet'     => 'Sylhet',
                                'Barisal'    => 'Barisal',
                                'Rangpur'    => 'Rangpur',
                                'Mymensingh' => 'Mymensingh',
                            ])
                            ->default('Dhaka')
                            ->required(),
                    ]),

                Forms\Components\Section::make('Subscriber Profile & GPON ONT Telemetry')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('full_name')
                            ->label('Full Name')
                            ->required()
                            ->maxLength(120),

                        Forms\Components\TextInput::make('phone')
                            ->label('Mobile Phone')
                            ->tel()
                            ->required()
                            ->maxLength(20),

                        Forms\Components\TextInput::make('ont_serial')
                            ->label('GPON ONT Serial')
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->nullable(),

                        Forms\Components\TextInput::make('optical_rx_dbm')
                            ->label('Optical RX Power (dBm)')
                            ->numeric()
                            ->step(0.01)
                            ->default(-19.50)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('Username')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('static_ipv4')
                    ->label('Assigned IP Address')
                    ->fontFamily('mono')
                    ->placeholder(fn (Subscriber $record): string => 'Pool: ' . ($record->tariff?->framed_pool ?? 'pppoe_active_pool'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('tariff.name')
                    ->label('Active Tariff')
                    ->description(fn (Subscriber $record): string => $record->tariff?->mikrotik_rate_limit ?? '')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('nas.shortname')
                    ->label('Linked NAS')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cgnat_shard_id')
                    ->label('Shard')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => sprintf('#%03d', $state))
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Subscriber Status')
                    ->options(SubscriberStatus::class),

                Tables\Filters\SelectFilter::make('tariff_id')
                    ->label('Tariff Package')
                    ->relationship('tariff', 'name'),

                Tables\Filters\SelectFilter::make('nas_id')
                    ->label('NAS Router')
                    ->relationship('nas', 'shortname'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'edit'   => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
